import { apiClient, unwrap } from './client';
import type { CmsPage } from '../types';

export async function fetchCmsPage(slug: string): Promise<CmsPage | null> {
  try {
    return await unwrap<CmsPage>(apiClient.get(`/cms/${slug}`));
  } catch {
    return null;
  }
}

/** Reads a section's `content` by key, falling back when the CMS has no row for it yet. */
export function sectionContent<T>(page: CmsPage | null, key: string, fallback: T): T {
  const section = page?.sections.find((s) => s.section_key === key);
  return (section?.content as T) ?? fallback;
}
