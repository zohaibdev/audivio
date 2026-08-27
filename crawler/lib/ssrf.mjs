import dns from 'node:dns';

const dnsLookup = dns.promises.lookup;

/**
 * Defense-in-depth SSRF check, run inside the Node/Playwright process itself.
 * The Laravel side already validates the URL before invoking this script,
 * but DNS can resolve differently between that check and this navigation
 * (DNS rebinding), and the page can redirect after launch — so every
 * hostname that Playwright is about to navigate to is re-resolved and
 * re-checked here too.
 */

function ipToBigInt(parts) {
  return parts.reduce((acc, part) => (acc << 8n) + BigInt(part), 0n);
}

function isPrivateIPv4(address) {
  const parts = address.split('.').map(Number);
  if (parts.length !== 4 || parts.some((p) => Number.isNaN(p))) return true;
  const ip = ipToBigInt(parts);

  const ranges = [
    ['0.0.0.0', 8],
    ['10.0.0.0', 8],
    ['100.64.0.0', 10],
    ['127.0.0.0', 8],
    ['169.254.0.0', 16], // includes cloud metadata 169.254.169.254
    ['172.16.0.0', 12],
    ['192.0.0.0', 24],
    ['192.0.2.0', 24],
    ['192.168.0.0', 16],
    ['198.18.0.0', 15],
    ['198.51.100.0', 24],
    ['203.0.113.0', 24],
    ['224.0.0.0', 4],
    ['240.0.0.0', 4],
    ['255.255.255.255', 32],
  ];

  return ranges.some(([base, prefix]) => {
    const baseIp = ipToBigInt(base.split('.').map(Number));
    const mask = prefix === 0 ? 0n : (~0n << BigInt(32 - prefix)) & 0xffffffffn;
    return (ip & mask) === (baseIp & mask);
  });
}

function isPrivateIPv6(address) {
  const normalized = address.toLowerCase();
  if (normalized === '::1') return true; // loopback
  if (normalized === '::') return true; // unspecified
  if (normalized.startsWith('::ffff:')) {
    // IPv4-mapped IPv6 — check the embedded IPv4 address.
    const mapped = normalized.replace('::ffff:', '');
    if (mapped.includes('.')) return isPrivateIPv4(mapped);
  }
  if (normalized.startsWith('fc') || normalized.startsWith('fd')) return true; // unique local fc00::/7
  if (normalized.startsWith('fe8') || normalized.startsWith('fe9') || normalized.startsWith('fea') || normalized.startsWith('feb')) return true; // link-local fe80::/10

  return false;
}

export function isPrivateAddress(address, family) {
  return family === 6 ? isPrivateIPv6(address) : isPrivateIPv4(address);
}

export async function assertHostnameIsPublic(hostname) {
  if (!hostname || hostname === 'localhost') {
    throw new SsrfError(`Blocked hostname: ${hostname}`);
  }

  let records;
  try {
    records = await dnsLookup(hostname, { all: true, verbatim: true });
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
