import fs from 'fs';
import path from 'path';

const links = JSON.parse(fs.readFileSync('D:\\Work\\stonewright-wp-mcp\\docs\\knowledge\\elementor\\_links.json', 'utf8'));

function getSlug(url) {
  const parts = url.replace(/\/$/, '').split('/');
  const last = parts[parts.length - 1];
  return last.toLowerCase().replace(/_/g, '-');
}

const allItems = [];

// Add all items with their metadata
for (const item of links.hubs.custom_widget || []) {
  allItems.push({ ...item, hub: 'custom_widget', applies_to: 'custom-widget' });
}
for (const item of links.hubs.developer || []) {
  allItems.push({ ...item, hub: 'developer', applies_to: 'developer' });
}
for (const item of links.hubs.theme_builder || []) {
  allItems.push({ ...item, hub: 'theme_builder', applies_to: 'theme-builder' });
}
for (const item of links.hubs.help_root || []) {
  allItems.push({ ...item, hub: 'help_root', applies_to: 'help-root' });
}
// Split editor into editor:v3 (first half) and editor:v4 (second half)
const halfEditor = Math.floor(links.hubs.editor.length / 2);
links.hubs.editor.slice(0, halfEditor).forEach(item => {
  allItems.push({ ...item, hub: 'editor', applies_to: 'editor:v3' });
});
links.hubs.editor.slice(halfEditor).forEach(item => {
  allItems.push({ ...item, hub: 'editor', applies_to: 'editor:v4' });
});
// Add widgets index
for (const item of links.hubs.widgets_index || []) {
  allItems.push({ ...item, hub: 'widgets_index', applies_to: `widget:${item.slug || getSlug(item.url)}` });
}
// Add widgets help
for (const item of links.hubs.widgets_help || []) {
  allItems.push({ ...item, hub: 'widgets_help', applies_to: `widget:${getSlug(item.url)}` });
}

// Partition into batches of maximum 25 items
const BATCH_SIZE = 25;
const outputDir = 'D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches';

// Clear existing batches first
if (fs.existsSync(outputDir)) {
  fs.readdirSync(outputDir).forEach(file => {
    fs.unlinkSync(path.join(outputDir, file));
  });
} else {
  fs.mkdirSync(outputDir, { recursive: true });
}

const totalBatches = Math.ceil(allItems.length / BATCH_SIZE);
for (let i = 0; i < totalBatches; i++) {
  const start = i * BATCH_SIZE;
  const end = start + BATCH_SIZE;
  const batchList = allItems.slice(start, end);
  const filePath = path.join(outputDir, `batch-${i + 1}.json`);
  fs.writeFileSync(filePath, JSON.stringify(batchList, null, 2), 'utf8');
  console.log(`Created batch-${i + 1}.json with ${batchList.length} items.`);
}

console.log(`Total items to harvest: ${allItems.length}`);
console.log(`Total batches generated: ${totalBatches}`);
