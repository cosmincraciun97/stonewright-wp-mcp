// SPDX-License-Identifier: AGPL-3.0-or-later

export interface GutenbergBlock {
  clientId: string;
  name: string;
  attributes: Record<string, unknown>;
  innerBlocks: GutenbergBlock[];
  parentClientId?: string;
  position?: number;
}

export interface GutenbergBlockSchema {
  name: string;
  title: string;
  category?: string;
  attributes: Record<string, unknown>;
  supports: Record<string, unknown>;
  source: "wordpress-block-registry";
}

export interface GutenbergRuntime {
  readonly postId: string;
  listBlockTypes(): Promise<GutenbergBlockSchema[]>;
  getBlockSchema(name: string): Promise<GutenbergBlockSchema | null>;
  getBlocks(parentClientId?: string): Promise<GutenbergBlock[]>;
  getBlock(clientId: string): Promise<GutenbergBlock | null>;
  insertBlock(input: { name: string; attributes: Record<string, unknown>; innerBlocks: GutenbergBlock[]; parentClientId?: string; position?: number }): Promise<GutenbergBlock>;
  updateBlock(clientId: string, attributes: Record<string, unknown>): Promise<void>;
  moveBlock(clientId: string, parentClientId?: string, position?: number): Promise<void>;
  deleteBlock(clientId: string): Promise<void>;
  undo(): Promise<void>;
  redo(): Promise<void>;
  save(): Promise<void>;
  isDirty(): Promise<boolean>;
  serialize(): Promise<string>;
}
