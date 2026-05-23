import fs from 'fs';
import path from 'path';
import crypto from 'crypto';

const combinedFile = process.argv[2];

if (!combinedFile || !fs.existsSync(combinedFile)) {
  console.error('Usage: node split-markdown.js <combined-markdown-file>');
  process.exit(1);
}

const content = fs.readFileSync(combinedFile, 'utf8');
const fileRegex = /===\s*FILE:\s*([^\s=]+)\s*===/g;

let match;
const files = [];

// Find all matches and their indices
const fileMarkers = [];
while ((match = fileRegex.exec(content)) !== null) {
  fileMarkers.push({
    path: match[1],
    index: match.index,
    length: match[0].length
  });
}

for (let i = 0; i < fileMarkers.length; i++) {
  const marker = fileMarkers[i];
  const startContent = marker.index + marker.length;
  const endContent = (i + 1 < fileMarkers.length) ? fileMarkers[i + 1].index : content.length;
  
  let fileContent = content.substring(startContent, endContent).trim();
  
  if (fileContent.length === 0) continue;

  // Let's compute the correct content_hash of the body
  // Split at the second '---' fence
  const fences = fileContent.split('---');
  if (fences.length >= 3) {
    // fences[0] is empty (or leading whitespace before the first ---)
    // fences[1] is frontmatter
    // fences[2+] is the body
    
    // Re-assemble the body to get exact body bytes
    const bodyContent = fences.slice(2).join('---').trim();
    
    // Calculate SHA-256 hash of the body bytes
    const hash = crypto.createHash('sha256').update(bodyContent, 'utf8').digest('hex');
    const fullHash = `sha256-${hash}`;
    
    // Find the content_hash line in the frontmatter and replace it
    let frontmatter = fences[1];
    if (frontmatter.includes('content_hash:')) {
      frontmatter = frontmatter.replace(/content_hash:\s*[^\r\n]*/, `content_hash: ${fullHash}`);
    } else {
      // If it doesn't have it, add it
      frontmatter = `content_hash: ${fullHash}\n${frontmatter}`;
    }
    
    // Re-assemble the full file content
    fileContent = `---\n${frontmatter.trim()}\n---\n\n${bodyContent}`;
  }

  // Determine correct absolute path
  const targetPath = path.isAbsolute(marker.path) ? marker.path : path.join('D:\\Work\\stonewright-wp-mcp', marker.path);
  const targetDir = path.dirname(targetPath);
  
  if (!fs.existsSync(targetDir)) {
    fs.mkdirSync(targetDir, { recursive: true });
  }

  fs.writeFileSync(targetPath, fileContent, 'utf8');
  console.log(`Saved and calculated hash for: ${targetPath}`);
}

console.log(`Successfully split and processed ${fileMarkers.length} files.`);
