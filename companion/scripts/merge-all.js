import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const scrapedDir = 'D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches\\scraped';
const dirs = fs.readdirSync(scrapedDir)
  .filter(d => d.startsWith('batch-') && fs.statSync(path.join(scrapedDir, d)).isDirectory());

console.log(`Found ${dirs.length} scraped directories to merge.`);

for (const dir of dirs) {
  const inputDir = path.join(scrapedDir, dir);
  const outputFile = path.join(scrapedDir, `${dir}-merged.json`);
  
  console.log(`Merging ${dir}...`);
  try {
    execSync(`node companion/scripts/merge-scraped.js ${inputDir} ${outputFile}`);
  } catch (err) {
    console.error(`Error merging ${dir}:`, err.message);
  }
}

console.log('ALL MERGES COMPLETED!');
