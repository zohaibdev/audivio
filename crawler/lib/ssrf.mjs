import dns from 'node:dns';

const dnsLookup = dns.promises.lookup;

/**
 * Defense-in-depth SSRF check, run inside the Node/Playwright process itself.
 * The Laravel side already validates the URL before invoking this script,
 * but DNS can resolve differently between that check and this navigation
 * (DNS rebinding), and the page can redirect after launch — so every
 * hostname that Playwright is about to request is re-resolved and
 * re-checked here too.
 *
 * The blocked ranges mirror `blocked_cidrs` in backend/config/crawler.php.
 * The two lists can't share code across the language boundary, so they are
 * kept deliberately in sync — a range added there belongs here as well.
 */

function ipToBigInt(parts) {
  return parts.reduce((acc, part) => (acc << 8n) + BigInt(part), 0n);
}

const IPV4_BLOCKED_RANGES = [
  ['0.0.0.0', 8], // "this network"
  ['10.0.0.0', 8], // private
  ['100.64.0.0', 10], // carrier-grade NAT
  ['127.0.0.0', 8], // loopback
  ['169.254.0.0', 16], // link-local, incl. cloud metadata 169.254.169.254
  ['172.16.0.0', 12], // private
  ['192.0.0.0', 24], // IETF protocol assignments
  ['192.0.2.0', 24], // TEST-NET-1
  ['192.88.99.0', 24], // deprecated 6to4 relay anycast
  ['192.168.0.0', 16], // private
  ['198.18.0.0', 15], // benchmarking
  ['198.51.100.0', 24], // TEST-NET-2
  ['203.0.113.0', 24], // TEST-NET-3
  ['224.0.0.0', 4], // multicast
  ['240.0.0.0', 4], // reserved, incl. 255.255.255.255 broadcast
];

function isPrivateIPv4(address) {
  const parts = address.split('.');
  if (parts.length !== 4) return true;

  const octets = parts.map((part) => {
    // Reject anything that is not a plain decimal octet. Leading zeros are
    // rejected rather than parsed because "0177.0.0.1" is octal to a real
    // resolver (127.0.0.1) but decimal to Number() — treating it as 177
    // would let loopback through.
    if (!/^(0|[1-9][0-9]{0,2})$/.test(part)) return NaN;
    return Number(part);
  });

  if (octets.some((o) => Number.isNaN(o) || o > 255)) return true;

  const ip = ipToBigInt(octets);

  return IPV4_BLOCKED_RANGES.some(([base, prefix]) => {
    const baseIp = ipToBigInt(base.split('.').map(Number));
    const mask = prefix === 0 ? 0n : (~0n << BigInt(32 - prefix)) & 0xffffffffn;
    return (ip & mask) === (baseIp & mask);
  });
}

const IPV6_BLOCKED_RANGES = [
  ['::', 128], // unspecified
  ['::1', 128], // loopback
  ['64:ff9b::', 96], // NAT64 — embeds an arbitrary IPv4 destination
  ['100::', 64], // discard-only
  ['2001:db8::', 32], // documentation
  ['fc00::', 7], // unique local
  ['fe80::', 10], // link-local
  ['ff00::', 8], // multicast
];

/**
 * Expand an IPv6 address to its full 128-bit integer. Returns null for
 * anything unparseable, which callers treat as private (fail closed).
 */
function ipv6ToBigInt(address) {
  let text = address.toLowerCase();

  // A zone index ("fe80::1%eth0") is not part of the address itself.
  const zone = text.indexOf('%');
  if (zone !== -1) text = text.slice(0, zone);

  // A trailing IPv4 form ("::ffff:127.0.0.1") is the low 32 bits written
  // in dotted-quad; rewrite it as two hextets so the rest is uniform.
  const dotted = text.match(/^(.*:)((?:\d{1,3}\.){3}\d{1,3})$/);
  if (dotted) {
    const octets = dotted[2].split('.').map(Number);
    if (octets.some((o) => Number.isNaN(o) || o < 0 || o > 255)) return null;
    const high = ((octets[0] << 8) | octets[1]).toString(16);
    const low = ((octets[2] << 8) | octets[3]).toString(16);
    text = `${dotted[1]}${high}:${low}`;
  }

  const halves = text.split('::');
  if (halves.length > 2) return null;

  const head = halves[0] === '' ? [] : halves[0].split(':');
  const tail = halves.length === 2 ? (halves[1] === '' ? [] : halves[1].split(':')) : [];

  let hextets;
  if (halves.length === 2) {
    const fill = 8 - head.length - tail.length;
    if (fill < 0) return null;
    hextets = [...head, ...Array(fill).fill('0'), ...tail];
  } else {
    hextets = head;
  }

  if (hextets.length !== 8) return null;

  let value = 0n;
  for (const hextet of hextets) {
    if (!/^[0-9a-f]{1,4}$/.test(hextet)) return null;
    value = (value << 16n) + BigInt(parseInt(hextet, 16));
  }

  return value;
}

function isPrivateIPv6(address) {
  const value = ipv6ToBigInt(address);
  if (value === null) return true; // fail closed

  // An IPv4-mapped address (::ffff:0:0/96) carries a real IPv4
  // destination; judge it by the IPv4 rules rather than the IPv6 ones.
  const mappedPrefix = 0xffffn << 32n;
  if (value >> 32n === mappedPrefix >> 32n) {
    const v4 = Number(value & 0xffffffffn);
    return isPrivateIPv4([v4 >>> 24, (v4 >>> 16) & 0xff, (v4 >>> 8) & 0xff, v4 & 0xff].join('.'));
  }

  return IPV6_BLOCKED_RANGES.some(([base, prefix]) => {
    const baseValue = ipv6ToBigInt(base);
    if (baseValue === null) return false;
    const mask = prefix === 0 ? 0n : (~0n << BigInt(128 - prefix)) & ((1n << 128n) - 1n);
    return (value & mask) === (baseValue & mask);
  });
}

export function isPrivateAddress(address, family) {
  return family === 6 ? isPrivateIPv6(address) : isPrivateIPv4(address);
}

/**
 * A hostname must be either a literal IP or a syntactically valid domain
 * name. Anything else — "2130706433", "0x7f.0.0.1", a shell payload — is
 * rejected before it reaches a resolver, because those integer spellings
 * resolve to loopback in Chromium even though they fail every IP parser.
 * A real TLD is never numeric, so requiring a non-numeric last label is
 * enough to catch every such form.
 */
function isWellFormedHostname(hostname) {
  // A genuine dotted-quad was already handled and returned by the caller,
  // so anything still all-digits-and-dots here is a malformed or
  // alternate-base spelling of an address, never a domain.
  if (/^[0-9.]+$/.test(hostname)) {
    return false;
  }

  if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/.test(hostname)) {
    return false;
  }

  const lastLabel = hostname.split('.').pop();

  return !/^(?:0x[0-9a-f]+|[0-9]+)$/.test(lastLabel);
}

export async function assertHostnameIsPublic(hostname) {
  if (!hostname) {
    throw new SsrfError(`Blocked hostname: ${hostname}`);
  }

  // Node's URL parser keeps IPv6 literals bracketed and preserves the DNS
  // root's trailing dot; strip both so the checks below see one spelling.
  let host = hostname.toLowerCase();
  if (host.startsWith('[') && host.endsWith(']')) {
    host = host.slice(1, -1);
  }
  host = host.replace(/\.$/, '');

  if (host === 'localhost' || host.endsWith('.localhost')) {
    throw new SsrfError(`Blocked hostname: ${hostname}`);
  }

  // A literal address needs no resolution — check it directly, so an
  // unresolvable-but-internal literal can't slip through on a DNS error.
  if (host.includes(':')) {
    if (isPrivateIPv6(host)) {
      throw new SsrfError(`Blocked private/internal address ${host}`);
    }

    return;
  }

  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(host)) {
    if (isPrivateIPv4(host)) {
      throw new SsrfError(`Blocked private/internal address ${host}`);
    }

    return;
  }

  if (!isWellFormedHostname(host)) {
    throw new SsrfError(`Invalid hostname: ${hostname}`);
  }

  let records;
  try {
    records = await dnsLookup(host, { all: true, verbatim: true });
  } catch (error) {
    throw new SsrfError(`DNS resolution failed for ${hostname}: ${error.message}`);
  }

  if (records.length === 0) {
    throw new SsrfError(`No DNS records resolved for ${hostname}`);
  }

  for (const record of records) {
    if (isPrivateAddress(record.address, record.family)) {
      throw new SsrfError(`Blocked private/internal address ${record.address} for ${hostname}`);
    }
  }
}

export class SsrfError extends Error {
  constructor(message) {
    super(message);
    this.name = 'SsrfError';
  }
}
