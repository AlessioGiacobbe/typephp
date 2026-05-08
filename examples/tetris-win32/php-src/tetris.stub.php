<?php

/**
 * Tetris Game C++ API declarations (stub)
 * These functions are implemented in C++, PHP layer only declares them
 */

// 游戏控制函数
function tetris_new(): mixed {}
function tetris_reset(mixed $game): void {}
function tetris_get_score(mixed $game): int {}
function tetris_is_game_over(mixed $game): bool {}

// 方块移动函数
function tetris_rotate(mixed $game): void {}
function tetris_move_down(mixed $game): bool {}
function tetris_move_left(mixed $game): bool {}
function tetris_move_right(mixed $game): bool {}
function tetris_hard_drop(mixed $game): void {}

// 获取游戏状态
function tetris_get_board(mixed $game): array {}
function tetris_get_current_piece(mixed $game): array {}

// Windows 窗口函数
function tetris_create_window(string $title): int {}
function tetris_show_window(int $hWnd, int $cmdShow): bool {}
function tetris_render(mixed $game, int $hWnd): void {}
function tetris_handle_key(mixed $game, int $keyCode): void {}

// 工具函数
function tetris_messagebox(int $hWnd, string $text, string $caption, int $uType): int {}
function tetris_post_quit(int $exitCode): void {}
