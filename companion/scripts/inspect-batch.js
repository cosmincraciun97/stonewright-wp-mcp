import fs from 'fs';

const batch = JSON.parse(fs.readFileSync('D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches\\scraped\\batch-8-merged.json', 'utf8'));
console.log(`Total items in batch: ${batch.length}`);
batch.forEach((item, index) => {
  console.log(`${index + 1}. Url: ${item.url}`);
  console.log(`   Slug: ${item.slug}`);
  console.log(`   Hub: ${item.hub}`);
  console.log(`   Applies to: ${item.applies_to}`);
  console.log(`   Title: ${item.title}`);
  console.log(`   H1: ${item.h1}`);
  console.log(`   Text length: ${item.text ? item.text.length : 0}`);
  if (item.error) {
    console.log(`   Error: ${item.error}`);
  }
});
