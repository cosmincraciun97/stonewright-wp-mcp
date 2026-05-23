import fs from 'fs';
import path from 'path';

const BASE_DIR = 'D:\\Work\\stonewright-wp-mcp\\docs\\knowledge\\elementor';
const HUBS = ['widgets', 'editor', 'theme', 'developer', 'help-root', 'custom-widget'];

let totalFiles = 0;
let pendingHashFiles = 0;
let tombstoneFiles = 0;
let realFiles = 0;

for (const hub of HUBS) {
  const dirPath = path.join(BASE_DIR, hub);
  if (!fs.existsSync(dirPath)) continue;

  const traverse = (dir) => {
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    for (const entry of entries) {
      const fullPath = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        traverse(fullPath);
      } else if (entry.isFile() && entry.name.endsWith('.md')) {
        totalFiles++;
        const content = fs.readFileSync(fullPath, 'utf8');
        
        const isTombstone = content.includes('tombstone: true');
        const hasPendingHash = content.includes('content_hash: sha256-pending');
        
        if (isTombstone) {
          tombstoneFiles++;
        } else if (hasPendingHash) {
          pendingHashFiles++;
        } else {
          realFiles++;
        }
      }
    }
  };

  traverse(dirPath);
}

console.log({
  totalFiles,
  pendingHashFiles,
  tombstoneFiles,
  realFiles
});
