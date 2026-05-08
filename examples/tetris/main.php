<?php

/**
 * Tetris Game - Main Logic in PHP
 * Using C++ API for graphics and game state management
 */

// Windows 常量定义
const SW_SHOW = 5;
const MB_OK = 0x00000000;
const VK_LEFT = 0x25;
const VK_RIGHT = 0x27;
const VK_UP = 0x26;
const VK_DOWN = 0x28;
const VK_SPACE = 0x20;
const WM_KEYDOWN = 0x0100;
const WM_PAINT = 0x000F;

function GetMessage(array &$lpMsg, int $hWnd, int $wMsgFilterMin, int $wMsgFilterMax): int {}

function TranslateMessage(array $lpMsg): int {}

function DispatchMessage(array $lpMsg): int {}

function PeekMessage(array &$lpMsg, int $hWnd, int $wMsgFilterMin, int $wMsgFilterMax, int $wRemoveMsg): int {}

function GetTickCount(): int {}

/**
 * 俄罗斯方块游戏主类
 */
class TetrisGame
{
    private mixed $game;
    private int $hWnd;
    private int $lastDropTime;
    private int $dropInterval;
    
    public function __construct()
    {
        // 创建游戏实例（C++ Box 对象）
        echo "正在创建游戏实例...\n";
        $this->game = tetris_new();
        echo "游戏实例已创建，类型: " . gettype($this->game) . "\n";
        
        if (!is_resource($this->game) && !is_object($this->game)) {
            echo "警告：game 不是有效的资源或对象类型\n";
        }
        
        $this->hWnd = 0;
        $this->lastDropTime = 0;
        $this->dropInterval = 500; // 初始下落间隔（毫秒）
    }
    
    /**
     * 初始化游戏窗口
     */
    public function initWindow(): void
    {
        echo "正在创建窗口...\n";
        $this->hWnd = tetris_create_window("俄罗斯方块 - PHP版");
        echo "窗口句柄: {$this->hWnd}\n";
        
        if ($this->hWnd == 0) {
            echo "错误：窗口创建失败！\n";
            return;
        }
        
        tetris_show_window($this->hWnd, SW_SHOW);
        
        echo "游戏窗口已创建\n";
        echo "控制说明:\n";
        echo "  ← → : 左右移动\n";
        echo "  ↑   : 旋转方块\n";
        echo "  ↓   : 加速下落\n";
        echo "  空格 : 直接落下\n";
        echo "\n";
    }
    
    /**
     * 游戏主循环
     */
    public function run(): void
    {
        $msg = [];
        $running = true;
        
        echo "游戏开始！\n";
        
        while ($running) {
            // 处理 Windows 消息
            while (PeekMessage($msg, $this->hWnd, 0, 0, 1)) {
                if (!isset($msg['message'])) {
                    continue;
                }
                
                $messageType = $msg['message'];
                
                if ($messageType == WM_KEYDOWN) {
                    $keyCode = isset($msg['wParam']) ? $msg['wParam'] : 0;
                    $this->handleKeyPress($keyCode);
                }
                
                // 检查是否收到退出消息
                if ($messageType == 0x0012) { // WM_QUIT
                    $running = false;
                    break;
                }
            }
            
            if (!$running) {
                break;
            }
            
            // 自动下落逻辑
            $currentTime = GetTickCount();
            if ($currentTime - $this->lastDropTime > $this->dropInterval) {
                if (!tetris_is_game_over($this->game)) {
                    tetris_move_down($this->game);
                    
                    // 根据分数调整速度
                    $score = tetris_get_score($this->game);
                    $this->dropInterval = max(100, 500 - intdiv($score, 500) * 50);
                }
                $this->lastDropTime = $currentTime;
            }
            
            // 渲染游戏画面
            tetris_render($this->game, $this->hWnd);
            
            // 检查游戏结束
            if (tetris_is_game_over($this->game)) {
                $this->handleGameOver();
                break;
            }
            
            // 控制帧率
            usleep(16000); // 约 60 FPS (16ms = 16000us)
        }
    }
    
    /**
     * 处理键盘输入
     */
    private function handleKeyPress(int $keyCode): void
    {
        switch ($keyCode) {
            case VK_LEFT:
                tetris_move_left($this->game);
                break;
                
            case VK_RIGHT:
                tetris_move_right($this->game);
                break;
                
            case VK_UP:
                tetris_rotate($this->game);
                break;
                
            case VK_DOWN:
                tetris_move_down($this->game);
                break;
                
            case VK_SPACE:
                tetris_hard_drop($this->game);
                break;
        }
    }
    
    /**
     * 处理游戏结束
     */
    private function handleGameOver(): void
    {
        $score = tetris_get_score($this->game);
        $message = "游戏结束！\n\n最终得分: {$score}\n\n是否重新开始？";
        
        $result = tetris_messagebox(
            $this->hWnd,
            $message,
            "游戏结束",
            MB_OK
        );
        
        if ($result == 1) { // IDOK
            // 重新开始游戏
            tetris_reset($this->game);
            $this->lastDropTime = GetTickCount();
            $this->dropInterval = 500;
            echo "游戏重新开始\n";
        } else {
            echo "游戏退出\n";
            tetris_post_quit(0);
        }
    }
    
    /**
     * 获取当前游戏状态
     */
    public function getStatus(): array
    {
        return [
            'score' => tetris_get_score($this->game),
            'gameOver' => tetris_is_game_over($this->game),
            'board' => tetris_get_board($this->game),
            'currentPiece' => tetris_get_current_piece($this->game),
        ];
    }
}

/**
 * 主函数
 */
function main(): void
{
    // 设置时区
    date_default_timezone_set('Asia/Shanghai');
    
    // 设置控制台编码为 UTF-8（Windows）
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        exec('chcp 65001 > nul');
    }
    
    echo "========================================\n";
    echo "   俄罗斯方块 - PHP 编译器演示\n";
    echo "========================================\n\n";
    
    // 创建并运行游戏
    $game = new TetrisGame();
    $game->initWindow();
    $game->run();
    
    echo "\n感谢游玩！\n";
}
