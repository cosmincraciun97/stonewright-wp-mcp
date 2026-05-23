import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const scrapedDir = 'D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches\\scraped';
const files = fs.readdirSync(scrapedDir)
  .filter(f => f.startsWith('batch-') && f.endsWith('-merged.json'))
  .sort((a, b) => {
    const numA = parseInt(a.match(/\d+/)[0]);
    const numB = parseInt(b.match(/\d+/)[0]);
    return numA - numB;
  });

console.log(`Found ${files.length} merged batch files to parse.`);

for (const file of files) {
  const filePath = path.join(scrapedDir, file);
  console.log(`\nParsing: ${file}...`);
  try {
    execSync(`node companion/scripts/parse-to-markdown.js ${filePath}`, { stdio: 'inherit' });
  } catch (err) {
    console.error(`Error parsing ${file}:`, err.message);
  }
}

console.log('\nALL BATCHES PARSING COMPLETED!');
