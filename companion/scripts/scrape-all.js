import { execSync } from 'child_process';
import fs from 'fs';
import path from 'path';

const batchesDir = 'D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches';
const files = fs.readdirSync(batchesDir)
  .filter(f => f.startsWith('batch-') && f.endsWith('.json'))
  .sort((a, b) => {
    const numA = parseInt(a.match(/\d+/)[0]);
    const numB = parseInt(b.match(/\d+/)[0]);
    return numA - numB;
  });

console.log(`Found ${files.length} batches to scrape.`);

for (let i = 0; i < files.length; i++) {
  const file = files[i];
  const filePath = path.join(batchesDir, file);
  console.log(`\n==================================================`);
  console.log(`STARTING BATCH ${i + 1}/${files.length}: ${file}`);
  console.log(`==================================================`);
  
  try {
    // Run scrape-batch.js synchronously for this batch
    // scrape-batch.js itself uses a concurrency of 3, which is very safe
    execSync(`node companion/scripts/scrape-batch.js ${filePath}`, { stdio: 'inherit' });
    console.log(`FINISHED BATCH ${file} successfully.`);
  } catch (err) {
    console.error(`Error processing batch ${file}:`, err.message);
  }
}

console.log('\n==================================================');
console.log('ALL BATCHES SCRAPING COMPLETED!');
console.log('==================================================');
