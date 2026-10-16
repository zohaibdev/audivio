/**
 * Client-side URL checking for the audit form.
 *
 * This exists for the error message, not for the security. Every rule here
 * is enforced again on the backend (UrlNormalizer + UrlSecurityValidator),
 * which is the only place a rejection actually means anything — anyone can
 * skip this file by calling the API directly. Its whole job is to tell
 * someone who mistyped an address why, immediately, instead of after a
 * round trip.
 */

const ALLOWED_PROTOCOLS = ['http:', 'https:'];

const MAX_URL_LENGTH = 2048;

/**
 * Hosts that will certainly be rejected by the backend's SSRF checks.
 * Deliberately a short, obvious list rather than an attempt to mirror the
 * server's ranges: a partial copy that drifts is worse than none, and the
 * server is what decides. These are here only so the common local-address
 * typo gets an explanation instead of a generic 422.
 */
const OBVIOUSLY_INTERNAL = /^(localhost|.*\.localhost|127\..*|10\..*|192\.168\..*|169\.254\..*|\[?::1\]?|0\.0\.0\.0)$/i;

export type UrlCheck = { ok: true; url: string } | { ok: false; error: string };

/**
 * Turn what someone typed into the absolute https URL the API expects.
 * A bare host gets the scheme it implies; anything already carrying one is
 * left alone so an unsupported scheme is reported rather than rewritten.
 */
export function normalizeUrlInput(input: string): string {
  const trimmed = input.trim();

  if (trimmed === '' || trimmed.includes('://')) {
    return trimmed;
  }

  // A colon before the first slash means a scheme was given (possibly an
  // opaque one like `javascript:`); prefixing https:// would disguise it.
  const firstColon = trimmed.indexOf(':');
  const firstSlash = trimmed.indexOf('/');
  if (firstColon !== -1 && (firstSlash === -1 || firstColon < firstSlash)) {
    return trimmed;
  }

  return `https://${trimmed}`;
}

export function checkAuditUrl(input: string): UrlCheck {
  const url = normalizeUrlInput(input);

  if (url === '') {
    return { ok: false, error: 'Enter a website address to audit.' };
  }

  if (url.length > MAX_URL_LENGTH) {
    return { ok: false, error: `That address is too long (limit ${MAX_URL_LENGTH} characters).` };
  }

  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return { ok: false, error: 'Enter a valid website address, e.g. https://example.com' };
  }

  if (!ALLOWED_PROTOCOLS.includes(parsed.protocol)) {
    return { ok: false, error: 'Only http:// and https:// addresses can be audited.' };
  }

  if (!parsed.hostname) {
    return { ok: false, error: 'Enter a valid website address, e.g. https://example.com' };
  }

  if (parsed.username || parsed.password) {
    return { ok: false, error: 'Remove the username and password from the address before auditing it.' };
  }

  if (OBVIOUSLY_INTERNAL.test(parsed.hostname)) {
    return { ok: false, error: 'Enter a publicly reachable website address — local and private addresses cannot be audited.' };
  }

  // A host with no dot and no port is a bare machine name on the local
  // network, not a site anyone can reach from the internet.
  if (!parsed.hostname.includes('.') && !parsed.hostname.startsWith('[')) {
    return { ok: false, error: 'Enter a full domain name, e.g. https://example.com' };
  }

  return { ok: true, url: parsed.toString() };
}
