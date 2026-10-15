import type { AstroComponentFactory } from 'astro/runtime/server/index.js';
import type { MarkdownHeading } from 'astro';
import type { Page } from '../lib/collection';
import type { Crumb } from '../components/Breadcrumbs.astro';

export interface TemplateProps {
  page: Page;
  crumbs: Crumb[];
  Content: AstroComponentFactory;
  headings: MarkdownHeading[];
  jsonLd: Record<string, unknown>[];
}
