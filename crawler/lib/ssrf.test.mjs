import { test, describe } from 'node:test';
import assert from 'node:assert/strict';
import { isPrivateAddress, assertHostnameIsPublic, SsrfError } from './ssrf.mjs';

describe('isPrivateAddress (IPv4)', () => {
  const blocked = [
    ['0.0.0.0', 'unspecified'],
    ['10.0.0.1', 'private class A'],
    ['10.255.255.255', 'private class A upper bound'],
    ['100.64.0.1', 'carrier-grade NAT'],
    ['127.0.0.1', 'loopback'],
    ['169.254.169.254', 'cloud metadata endpoint'],
    ['169.254.0.1', 'link-local'],
    ['172.16.0.1', 'private class B'],
    ['172.31.255.255', 'private class B upper bound'],
    ['192.0.2.1', 'TEST-NET-1'],
    ['192.168.0.1', 'private class C'],
    ['192.168.255.255', 'private class C upper bound'],
    ['198.18.0.1', 'benchmark range'],
    ['198.51.100.1', 'TEST-NET-2'],
    ['203.0.113.1', 'TEST-NET-3'],
    ['224.0.0.1', 'multicast'],
    ['255.255.255.255', 'broadcast'],
  ];

  for (const [ip, label] of blocked) {
    test(`blocks ${label} (${ip})`, () => {
      assert.equal(isPrivateAddress(ip, 4), true);
    });
  }

  const allowed = [
    ['8.8.8.8', "Google DNS"],
    ['1.1.1.1', 'Cloudflare DNS'],
    ['172.15.255.255', 'just below the private class B range'],
    ['172.32.0.0', 'just above the private class B range'],
    ['11.0.0.1', 'just above the private class A range'],
  ];

  for (const [ip, label] of allowed) {
    test(`allows ${label} (${ip})`, () => {
      assert.equal(isPrivateAddress(ip, 4), false);
    });
  }

  test('treats a malformed address as private (fail closed)', () => {
    assert.equal(isPrivateAddress('not-an-ip', 4), true);
  });
});

describe('isPrivateAddress (IPv6)', () => {
  const blocked = [
    ['::1', 'loopback'],
    ['::', 'unspecified'],
    ['fc00::1', 'unique local fc00::/7'],
    ['fd00::1', 'unique local fd00::/7'],
    ['fe80::1', 'link-local fe80::/10'],
    ['::ffff:127.0.0.1', 'IPv4-mapped loopback'],
    ['::ffff:192.168.1.1', 'IPv4-mapped private range'],
  ];

  for (const [ip, label] of blocked) {
    test(`blocks ${label} (${ip})`, () => {
      assert.equal(isPrivateAddress(ip, 6), true);
    });
  }

  test('allows a public IPv6 address', () => {
    assert.equal(isPrivateAddress('2001:4860:4860::8888', 6), false); // Google public DNS
  });

  test('allows an IPv4-mapped public address', () => {
    assert.equal(isPrivateAddress('::ffff:8.8.8.8', 6), false);
  });
});

describe('assertHostnameIsPublic', () => {
  test('rejects the literal hostname "localhost"', async () => {
    await assert.rejects(() => assertHostnameIsPublic('localhost'), SsrfError);
  });

  test('rejects an empty hostname', async () => {
    await assert.rejects(() => assertHostnameIsPublic(''), SsrfError);
  });

  test('rejects a loopback IP literal (no real DNS query needed)', async () => {
    await assert.rejects(() => assertHostnameIsPublic('127.0.0.1'), SsrfError);
  });

  test('rejects a private IP literal', async () => {
    await assert.rejects(() => assertHostnameIsPublic('192.168.1.1'), SsrfError);
  });

  test('rejects the cloud metadata IP literal', async () => {
    await assert.rejects(() => assertHostnameIsPublic('169.254.169.254'), SsrfError);
  });

  test('rejects an IPv6 loopback literal', async () => {
    await assert.rejects(() => assertHostnameIsPublic('::1'), SsrfError);
  });

  test('allows a public IP literal', async () => {
    await assert.doesNotReject(() => assertHostnameIsPublic('8.8.8.8'));
  });

  // Real-world sanity check against actual DNS resolution, mirroring the
  // PHP-side UrlSecurityValidatorTest's equivalent case.
  test('resolves and allows a stable real public hostname', async () => {
    await assert.doesNotReject(() => assertHostnameIsPublic('example.com'));
  });
});

// --- Ranges and spellings added in the Phase 1 hardening pass ---

describe('isPrivateAddress: ranges the original list missed', () => {
  const blockedV6 = [
    ['64:ff9b::a9fe:a9fe', 'NAT64-wrapped cloud metadata'],
    ['64:ff9b::7f00:1', 'NAT64-wrapped loopback'],
    ['ff02::1', 'multicast'],
    ['2001:db8::1', 'documentation range'],
    ['100::1', 'discard-only'],
    ['::ffff:7f00:1', 'IPv4-mapped loopback in hex form'],
    ['::ffff:a9fe:a9fe', 'IPv4-mapped cloud metadata in hex form'],
    ['fe80::1%eth0', 'link-local with a zone index'],
  ];

  for (const [ip, label] of blockedV6) {
    test(`blocks ${label} (${ip})`, () => {
      assert.equal(isPrivateAddress(ip, 6), true);
    });
  }

  test('still allows a public IPv6 address after the range additions', () => {
    assert.equal(isPrivateAddress('2606:4700:4700::1111', 6), false);
  });

  test('treats a malformed IPv6 address as private (fail closed)', () => {
    assert.equal(isPrivateAddress('fe80::gg::1', 6), true);
  });

  // An octal octet is read as octal by real resolvers but as decimal by
  // Number(), so "0177.0.0.1" must not be evaluated as 177.0.0.1.
  test('blocks an octal-spelled loopback octet', () => {
    assert.equal(isPrivateAddress('0177.0.0.1', 4), true);
  });

  test('blocks an octet above 255', () => {
    assert.equal(isPrivateAddress('999.0.0.1', 4), true);
  });
});

describe('assertHostnameIsPublic: hostname forms', () => {
  test('rejects a subdomain of localhost', async () => {
    await assert.rejects(() => assertHostnameIsPublic('api.localhost'), SsrfError);
  });

  test('rejects localhost written with the DNS root dot', async () => {
    await assert.rejects(() => assertHostnameIsPublic('localhost.'), SsrfError);
  });

  test('rejects a bracketed IPv6 loopback literal as Node reports it', async () => {
    await assert.rejects(() => assertHostnameIsPublic('[::1]'), SsrfError);
  });

  test('rejects the decimal integer spelling of loopback', async () => {
    // 2130706433 === 127.0.0.1 to Chromium's resolver, but it parses as
    // neither an IP nor a domain — so it must be refused, not looked up.
    await assert.rejects(() => assertHostnameIsPublic('2130706433'), SsrfError);
  });

  test('rejects the octal spelling of loopback', async () => {
    await assert.rejects(() => assertHostnameIsPublic('0177.0.0.1'), SsrfError);
  });

  test('rejects the hex spelling of loopback', async () => {
    await assert.rejects(() => assertHostnameIsPublic('0x7f.0.0.1'), SsrfError);
  });

  test('rejects a NAT64-wrapped metadata literal', async () => {
    await assert.rejects(() => assertHostnameIsPublic('64:ff9b::a9fe:a9fe'), SsrfError);
  });

  test('rejects a shell-injection payload in the hostname', async () => {
    await assert.rejects(() => assertHostnameIsPublic('example.com;curl evil.test'), SsrfError);
  });

  test('rejects an XSS payload in the hostname', async () => {
    await assert.rejects(() => assertHostnameIsPublic('<script>alert(1)</script>'), SsrfError);
  });

  test('allows a real public hostname written with the DNS root dot', async () => {
    await assert.doesNotReject(() => assertHostnameIsPublic('example.com.'));
  });
});
