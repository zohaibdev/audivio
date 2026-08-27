import { apiClient, unwrap } from './client';
import type { Paginated } from '../types';

export interface MyWebsite {
  id: number;
  url: string;
  domain: string;
  audits_count: number;
  latestAudit: {
    id: number;
    status: string;
    created_at: string;
    scores: { category: string; score: number }[];
  } | null;
}

export function fetchMyWebsites(page = 1) {
  return unwrap<Paginated<MyWebsite>>(apiClient.get('/websites', { params: { page } }));
}

export interface MyAudit {
  id: number;
  status: string;
  pages_crawled: number;
  max_pages: number;
  created_at: string;
  website: { id: number; url: string; domain: string };
  scores: { category: string; score: number }[];
}

export function fetchMyAudits(page = 1) {
  return unwrap<Paginated<MyAudit>>(apiClient.get('/audits', { params: { page } }));
}
