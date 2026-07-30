import fs from 'fs';
import path from 'path';

const inputDir = process.argv[2];
const outputFile = process.argv[3];

if (!inputDir || !outputFile) {
  console.error('Usage: node merge-scraped.js <input-dir-path> <output-file-path>');
  process.exit(1);
}

const merged = [];
const files = fs.readdirSync(inputDir).filter(f => f.endsWith('.json'));

for (const file of files) {
  const filePath = path.join(inputDir, file);
  const data = JSON.parse(fs.readFileSync(filePath, 'utf8'));
  
  // Clean text but keep newlines intact to preserve paragraph structure!
  if (data.text) {
    data.text = data.text
      .replace(/[^\S\r\n]+/g, ' ') // collapse horizontal spaces
      .replace(/\r\n/g, '\n')      // normalize newlines
      .replace(/\n{3,}/g, '\n\n')  // collapse multiple consecutive newlines to max 2
      .trim();
  }
  
  merged.push({
    url: data.url,
    slug: data.slug,
    hub: data.hub,
    applies_to: data.applies_to,
    title: data.title,
    h1: data.h1,
    fetchedAt: data.fetchedAt,
    text: data.text,
    links: data.links,
    error: data.error || null
  });
}

fs.writeFileSync(outputFile, JSON.stringify(merged, null, 2), 'utf8');
console.log(`Merged ${merged.length} files into ${outputFile}`);
