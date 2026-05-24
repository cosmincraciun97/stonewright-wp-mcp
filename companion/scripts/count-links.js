import fs from 'fs';

const links = JSON.parse(fs.readFileSync('D:\\Work\\stonewright-wp-mcp\\docs\\knowledge\\elementor\\_links.json', 'utf8'));

const hubCounts = {};
let total = 0;

for (const [hub, urls] of Object.entries(links.hubs)) {
  hubCounts[hub] = urls.length;
  total += urls.length;
}

console.log('Hub Counts:', hubCounts);
console.log('Total URLs:', total);
