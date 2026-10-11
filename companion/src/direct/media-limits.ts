/**
 * What `stonewright-media-upload` will send to the media library.
 *
 * - Size: at most `STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES` (default 25 MiB, never
 *   more than 512 MiB). A local file is measured before it is read and a
 *   download is cut off as soon as it grows past the limit.
 * - Type: an allowlist of image, audio, video and document extensions. SVG,
 *   HTML, XML, PHP and other script, executable and archive types are not on it,
 *   and `confirm: true` does not widen it. WordPress still applies its own
 *   rules for the account on top of this.
 * - Content: for images and PDF the first bytes must match the extension, and no
 *   file may start with markup or contain a PHP open tag near its start.
 * - Name: reduced to one safe file name (no directories, no control or
 *   direction-override characters, no characters WordPress would drop).
 */

export const MEDIA_MAX_BYTES_ENV = "STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES";
export const DEFAULT_MEDIA_MAX_BYTES = 25 * 1024 * 1024;
const HARD_MEDIA_MAX_BYTES = 512 * 1024 * 1024;
const MAX_NAME_LENGTH = 120;
const HEAD_BYTES = 1024;

export function mediaBlocked(message: string, code: string): Error {
  return new Error(`${message} code=${code}`);
}

export function mediaMaxBytes(env: NodeJS.ProcessEnv): number {
  const raw = (env[MEDIA_MAX_BYTES_ENV] ?? "").trim();
  if (!/^\d+$/.test(raw)) return DEFAULT_MEDIA_MAX_BYTES;
  const value = Number(raw);
  if (!Number.isSafeInteger(value) || value < 1) return DEFAULT_MEDIA_MAX_BYTES;
  return Math.min(value, HARD_MEDIA_MAX_BYTES);
}

export function mediaTooLarge(limit: number): Error {
  return mediaBlocked(
    `media-upload file is larger than the limit of ${limit} bytes (set ${MEDIA_MAX_BYTES_ENV} to change it).`,
    "media_file_too_large",
  );
}

/** Allowed extensions and the type sent with the file. */
const MEDIA_TYPES: Readonly<Record<string, string>> = {
  jpg: "image/jpeg",
  jpeg: "image/jpeg",
  jpe: "image/jpeg",
  png: "image/png",
  gif: "image/gif",
  webp: "image/webp",
  avif: "image/avif",
  bmp: "image/bmp",
  ico: "image/x-icon",
  heic: "image/heic",
  mp4: "video/mp4",
  m4v: "video/x-m4v",
  mov: "video/quicktime",
  webm: "video/webm",
  ogv: "video/ogg",
  mp3: "audio/mpeg",
  m4a: "audio/mp4",
  ogg: "audio/ogg",
  wav: "audio/wav",
  pdf: "application/pdf",
  doc: "application/msword",
  docx: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  xls: "application/vnd.ms-excel",
  xlsx: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  ppt: "application/vnd.ms-powerpoint",
  pptx: "application/vnd.openxmlformats-officedocument.presentationml.presentation",
  odt: "application/vnd.oasis.opendocument.text",
  ods: "application/vnd.oasis.opendocument.spreadsheet",
  odp: "application/vnd.oasis.opendocument.presentation",
  rtf: "application/rtf",
  txt: "text/plain",
  csv: "text/csv",
};

/** Content types that name an allowed extension when the URL has none. */
const EXTENSION_BY_CONTENT_TYPE: Readonly<Record<string, string>> = {
  "image/jpeg": "jpg",
  "image/png": "png",
  "image/gif": "gif",
  "image/webp": "webp",
  "image/avif": "avif",
  "image/bmp": "bmp",
  "image/heic": "heic",
  "video/mp4": "mp4",
  "video/webm": "webm",
  "audio/mpeg": "mp3",
  "application/pdf": "pdf",
};

/** Inner extensions that must not survive in the middle of a name (a.php.png). */
const BLOCKED_INNER = new Set([
  "php", "php3", "php4", "php5", "php7", "php8", "phtml", "phar", "pht", "phps",
  "pl", "py", "cgi", "asp", "aspx", "jsp", "sh", "bash", "bat", "cmd", "exe",
  "dll", "js", "mjs", "html", "htm", "xhtml", "shtml", "svg", "svgz", "xml",
  "htaccess",
]);

export function mediaTypeFor(extension: string): string | undefined {
  return Object.hasOwn(MEDIA_TYPES, extension) ? MEDIA_TYPES[extension] : undefined;
}

function extensionOf(name: string): string {
  const dot = name.lastIndexOf(".");
  return dot <= 0 ? "" : name.slice(dot + 1).toLowerCase();
}

/** Control, zero-width and direction-override characters, and the byte order mark. */
function stripInvisible(value: string): string {
  return Array.from(value)
    .filter((char) => {
      const code = char.codePointAt(0) ?? 0;
      return !(
        code <= 0x1f ||
        code === 0x7f ||
        (code >= 0x200b && code <= 0x200f) ||
        (code >= 0x202a && code <= 0x202e) ||
        (code >= 0x2066 && code <= 0x2069) ||
        code === 0xfeff
      );
    })
    .join("");
}

/** One safe file name; empty when nothing usable is left. */
export function sanitizeMediaFilename(raw: string): string {
  let name = stripInvisible(raw)
    .split(/[\\/]/)
    .pop() ?? "";
  name = name
    .replace(/[?[\]=<>:;,'"&$#*()|~`!{}%+^@]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-{2,}/g, "-")
    .replace(/\.{2,}/g, ".")
    .replace(/^[-_]+|[._-]+$/g, "");
  if (name === "") return "";
  // Only an extension was left (".png"): give it a stem.
  if (name.startsWith(".")) name = `upload${name}`;

  const dot = name.lastIndexOf(".");
  let stem = dot > 0 ? name.slice(0, dot) : name;
  const ext = dot > 0 ? name.slice(dot + 1) : "";
  stem = stem
    .split(".")
    .map((part) => (BLOCKED_INNER.has(part.toLowerCase()) ? `${part}_` : part))
    .join(".");
  if (stem.length + ext.length + 1 > MAX_NAME_LENGTH) {
    stem = stem.slice(0, Math.max(1, MAX_NAME_LENGTH - ext.length - 1));
  }
  return ext === "" ? stem : `${stem}.${ext}`;
}

function typeNotAllowed(extension: string): Error {
  return mediaBlocked(
    `media-upload does not accept ${extension === "" ? "a file with no extension" : `.${extension.slice(0, 16).replace(/[^a-z0-9]/g, "?")} files`}. Allowed: images, audio, video, PDF, Office and OpenDocument files, txt and csv. SVG, HTML, XML, PHP, scripts, executables and archives are never uploaded.`,
    "media_type_not_allowed",
  );
}

/**
 * The name to upload and its extension. `supplied` is the caller's filename,
 * `source` the name found in the path or URL. A name with no extension takes
 * the source's, then one implied by the response content type; a blocked
 * extension is never replaced by the content type.
 */
export function chooseMediaFilename(input: {
  supplied?: string | undefined;
  source?: string | undefined;
  contentType?: string | undefined;
}): { filename: string; extension: string } {
  const source = sanitizeMediaFilename(input.source ?? "");
  let name = sanitizeMediaFilename(input.supplied ?? "");
  if (name === "") name = source;
  let extension = extensionOf(name);
  if (extension === "") {
    const fromSource = extensionOf(source);
    const mime = (input.contentType ?? "").split(";")[0]?.trim().toLowerCase() ?? "";
    extension = fromSource !== "" ? fromSource : (Object.hasOwn(EXTENSION_BY_CONTENT_TYPE, mime) ? EXTENSION_BY_CONTENT_TYPE[mime] : "");
    if (extension !== "") name = `${name === "" ? "upload" : name}.${extension}`;
  }
  if (mediaTypeFor(extension) === undefined) throw typeNotAllowed(extension);
  return { filename: name, extension };
}

function startsWith(bytes: Uint8Array, signature: number[]): boolean {
  return signature.every((value, index) => bytes[index] === value);
}

const isJpeg = (b: Uint8Array): boolean => startsWith(b, [0xff, 0xd8, 0xff]);

const SIGNATURES: Readonly<Record<string, (head: Uint8Array) => boolean>> = {
  png: (b) => startsWith(b, [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
  jpg: isJpeg,
  jpeg: isJpeg,
  jpe: isJpeg,
  gif: (b) => startsWith(b, [0x47, 0x49, 0x46, 0x38]),
  webp: (b) =>
    startsWith(b, [0x52, 0x49, 0x46, 0x46]) &&
    Buffer.from(b.subarray(8, 12)).toString("latin1") === "WEBP",
  pdf: (b) => Buffer.from(b).toString("latin1").includes("%PDF-"),
};

const MARKUP_START =
  /^(<\?php|<\?=|<\?xml|<!doctype|<!--|<html|<head|<body|<svg|<script|<iframe)/i;

/** Throws unless the first bytes fit the extension and are not markup or script. */
export function assertMediaContent(extension: string, bytes: Uint8Array): void {
  const head = bytes.subarray(0, HEAD_BYTES);
  const mismatch = (): Error =>
    mediaBlocked(
      "media-upload content does not match its file type (markup, script or a different format).",
      "media_content_mismatch",
    );
  const skip = head[0] === 0xef && head[1] === 0xbb && head[2] === 0xbf ? 3 : 0;
  const text = Buffer.from(head.subarray(skip)).toString("latin1").trimStart();
  if (MARKUP_START.test(text) || /<\?php/i.test(text)) throw mismatch();
  const signature = Object.hasOwn(SIGNATURES, extension) ? SIGNATURES[extension] : undefined;
  if (signature && !signature(head)) throw mismatch();
}
