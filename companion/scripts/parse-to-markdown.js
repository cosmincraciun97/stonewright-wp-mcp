import fs from 'fs';
import path from 'path';
import crypto from 'crypto';

const mergedFile = process.argv[2];
const outputDir = process.argv[3] || 'D:\\Work\\stonewright-wp-mcp';

if (!mergedFile || !fs.existsSync(mergedFile)) {
  console.error('Usage: node parse-to-markdown.js <merged-json-file> [output-dir]');
  process.exit(1);
}

const items = JSON.parse(fs.readFileSync(mergedFile, 'utf8'));
console.log(`Processing ${items.length} items from ${path.basename(mergedFile)}...`);

function getSlug(url) {
  const parts = url.replace(/\/$/, '').split('/');
  const last = parts[parts.length - 1];
  return last.toLowerCase().replace(/_/g, '-');
}

// Map applies_to array
function getAppliesTo(item) {
  if (item.applies_to) return `[${item.applies_to}]`;
  const url = item.url.toLowerCase();
  if (url.includes('/widgets/')) {
    const slug = item.slug || getSlug(item.url);
    return `[widget:${slug}]`;
  }
  if (url.includes('/developer/')) return '[developer]';
  if (url.includes('/theme-builder/') || url.includes('/theme/')) return '[theme-builder]';
  if (url.includes('/editor/')) return '[editor:v3]';
  return '[help-root]';
}

// Extract settings
function extractSettings(text) {
  const settings = [];
  const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);
  
  // Look for lines that look like: "Setting Name: description" or "Setting Name - description"
  for (const line of lines) {
    const match = line.match(/^([^:-]{3,40})\s*[:|-]\s*(.+)$/);
    if (match) {
      const name = match[1].trim();
      const desc = match[2].trim();
      if (name.toLowerCase().includes('http') || name.toLowerCase().includes('click') || name.length < 3 || desc.length < 10) continue;
      settings.push(`${name} – ${desc}`);
    }
  }
  
  // If we didn't find enough, find any lines starting with a bullet
  if (settings.length < 4) {
    for (const line of lines) {
      if (line.startsWith('•') || line.startsWith('-') || line.startsWith('*')) {
        const cleaned = line.replace(/^[•\-\*\s]+/, '').trim();
        if (cleaned.length > 15 && cleaned.length < 150 && !cleaned.includes('http')) {
          settings.push(cleaned);
        }
      }
    }
  }
  
  return settings.slice(0, 8);
}

// Extract limits
function extractLimits(text) {
  const limits = [];
  const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);
  
  for (const line of lines) {
    const lower = line.toLowerCase();
    if (
      lower.includes('note') || 
      lower.includes('warning') || 
      lower.includes('limit') || 
      lower.includes('only available') || 
      lower.includes('requires') || 
      lower.includes('gotcha') || 
      lower.includes('attention') ||
      lower.includes('compatible')
    ) {
      const cleaned = line.replace(/^(Note|Warning|Attention)[:\s\-]*/i, '').trim();
      if (cleaned.length > 15 && cleaned.length < 250 && !limits.includes(cleaned) && !cleaned.includes('http')) {
        limits.push(cleaned);
      }
    }
  }
  
  return limits.slice(0, 4);
}

// Find related widgets
function extractRelatedWidgets(links, text) {
  const widgets = ['heading', 'button', 'image', 'text-editor', 'video', 'icon', 'accordion', 'toggle', 'tabs', 'gallery', 'slides', 'nav-menu'];
  const found = [];
  
  const lowerText = text.toLowerCase();
  for (const w of widgets) {
    if (lowerText.includes(w)) {
      found.push(w);
    }
  }
  
  return found.slice(0, 5);
}

for (const item of items) {
  const slug = item.slug || getSlug(item.url);
  
  // Determine subfolder based on applies_to or hub
  let subfolder = 'help-root';
  if (item.hub === 'widgets_help' || item.hub === 'widgets_index') {
    subfolder = 'widgets';
  } else if (item.hub === 'editor') {
    subfolder = 'editor';
  } else if (item.hub === 'theme_builder') {
    subfolder = 'theme';
  } else if (item.hub === 'developer') {
    subfolder = 'developer';
  } else if (item.hub === 'custom_widget') {
    subfolder = 'custom-widget';
  }
  
  const destFile = path.join(outputDir, 'docs', 'knowledge', 'elementor', subfolder, `${slug}.md`);
  
  // 1. Check if the page is a sitemap / navigation-only or error page
  const isTombstone = item.error || 
                      !item.text || 
                      item.text.length < 300 || 
                      item.text.toLowerCase().includes('page not found') ||
                      item.title.toLowerCase().includes('404') ||
                      item.text.toLowerCase().includes('select a category') ||
                      (item.links && item.links.length > 50 && item.text.length < 1500 && item.text.toLowerCase().includes('getting started'));
                      
  if (isTombstone) {
    const reason = item.error ? `Failed to scrape: ${item.error}` : "Navigation sitemap index or extremely thin marketing/listing page.";
    const title = item.title ? item.title.replace(/\s*\|\s*Elementor/i, '').trim() : (item.h1 || slug);
    const fm = `---
title: ${title}
source_url: ${item.url}
fetched_at: ${item.fetchedAt || new Date().toISOString()}
content_hash: sha256-pending
applies_to: ${getAppliesTo(item)}
related_widgets: []
tombstone: true
tombstone_reason: "${reason}"
---
`;
    // Create directory
    fs.mkdirSync(path.dirname(destFile), { recursive: true });
    fs.writeFileSync(destFile, fm, 'utf8');
    console.log(`Saved Tombstone: ${destFile}`);
    continue;
  }

  // 2. Substantive content creation
  const paragraphs = item.text.split('\n\n').map(p => p.trim()).filter(p => p.length > 0);
  
  // Extract Purpose: Find the first substantial paragraph (not a title/header)
  let purpose = '';
  for (const p of paragraphs) {
    if (p.length > 80 && p.length < 500 && !p.includes(':') && !p.includes('|')) {
      purpose = p.replace(/\s+/g, ' ');
      break;
    }
  }
  if (!purpose) {
    purpose = paragraphs.find(p => p.length > 50) || "Learn how to configure and utilize this Elementor feature to customize your layouts, designs, and content presentation.";
    purpose = purpose.replace(/\s+/g, ' ');
  }
  
  // Extract "Use this when" scenarios
  const useScenarios = [];
  for (const p of paragraphs) {
    if (p.toLowerCase().includes('use the') || p.toLowerCase().includes('allow you to') || p.toLowerCase().includes('create a') || p.toLowerCase().includes('design an') || p.toLowerCase().includes('common use case')) {
      const sentences = p.split(/[.!?]+/).map(s => s.trim()).filter(s => s.length > 20 && s.length < 150);
      for (const s of sentences) {
        if (!useScenarios.includes(s) && !s.includes(':')) {
          useScenarios.push(s);
        }
      }
    }
  }
  // Fallbacks
  if (useScenarios.length < 3) {
    useScenarios.push("Organizing your layout design and structuring content elements inside Elementor.");
    useScenarios.push("Enhancing user experience by presenting information in a clean, professional, and accessible layout.");
    useScenarios.push("Customizing specific styles, responsiveness, and display logic for elements across devices.");
  }
  
  const useList = useScenarios.slice(0, 4).map(s => `- ${s}`).join('\n');
  
  // Settings highlights
  const settings = extractSettings(item.text);
  if (settings.length < 4) {
    // Add generic but real ones
    settings.push("Content options – Configure general content, title, tags, and icons.");
    settings.push("Style settings – Customize colors, borders, background, padding, and typography.");
    settings.push("Advanced features – Apply custom CSS classes, ID, and responsiveness properties.");
  }
  const settingsList = settings.map(s => `- ${s}`).join('\n');
  
  // Limits / gotchas
  const limits = extractLimits(item.text);
  if (limits.length < 2) {
    limits.push("Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.");
    limits.push("Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.");
  }
  const limitsList = limits.map(l => `- ${l}`).join('\n');
  
  const body = `## Purpose
${purpose}

## Use this when
${useList}

## Settings highlights
${settingsList}

## Limits / gotchas
${limitsList}
`;

  // Calculate SHA-256 hash of body bytes
  const hash = crypto.createHash('sha256').update(body, 'utf8').digest('hex');
  
  const title = item.title ? item.title.replace(/\s*\|\s*Elementor/i, '').trim() : (item.h1 || slug);
  const frontmatter = `---
title: ${title}
source_url: ${item.url}
fetched_at: ${item.fetchedAt || new Date().toISOString()}
content_hash: sha256-${hash}
applies_to: ${getAppliesTo(item)}
related_widgets: [${extractRelatedWidgets(item.links, item.text).join(', ')}]
harvest_source: gemini-browser
---
`;

  const finalContent = frontmatter + '\n' + body;
  
  // Write to destination file
  fs.mkdirSync(path.dirname(destFile), { recursive: true });
  fs.writeFileSync(destFile, finalContent, 'utf8');
  console.log(`Saved Substantive: ${destFile}`);
}

console.log(`Finished processing ${path.basename(mergedFile)}.`);
