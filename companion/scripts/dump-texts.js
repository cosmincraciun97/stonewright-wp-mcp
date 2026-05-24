import fs from 'fs';

const batch = JSON.parse(fs.readFileSync('D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches\\scraped\\batch-8-merged.json', 'utf8'));

let output = '';
batch.forEach((item, index) => {
  output += `=========================================\n`;
  output += `ITEM ${index + 1}: ${item.url}\n`;
  output += `SLUG: ${item.slug}\n`;
  output += `TITLE: ${item.title}\n`;
  output += `H1: ${item.h1}\n`;
  output += `TEXT:\n${item.text}\n\n`;
});

fs.writeFileSync('D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches\\scraped\\batch-8-texts.txt', output, 'utf8');
console.log('Saved texts to D:\\Work\\stonewright-wp-mcp\\companion\\scripts\\batches\\scraped\\batch-8-texts.txt');
