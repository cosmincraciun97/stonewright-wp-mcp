// SPDX-License-Identifier: AGPL-3.0-or-later
export interface BlockDefinition { name: string; attributes: Record<string, Record<string, unknown>>; }
export interface BlockSpec { name: string; attributes: Record<string, unknown>; innerBlocks: BlockSpec[]; }
export interface BlockNode { clientId: string; name: string; attributes: Record<string, unknown>; innerBlocks: BlockNode[]; }
export interface NativeBlockPort {
  identity(): string; registeredBlocks(): Promise<BlockDefinition[]>; tree(): Promise<BlockNode[]>; block(id: string): Promise<BlockNode | null>;
  insert(spec: BlockSpec, parent?: string, index?: number): Promise<string[]>; update(id: string, attrs: Record<string, unknown>): Promise<void>; move(ids: string[], parent?: string, index?: number): Promise<void>; remove(ids: string[]): Promise<void>;
  undo(): Promise<void>; redo(): Promise<void>; save(): Promise<void>; dirty(): Promise<boolean>; serialize(nodes: BlockNode[]): Promise<string>; checkpoint(): Promise<unknown>; restore(checkpoint: unknown): Promise<void>;
}
