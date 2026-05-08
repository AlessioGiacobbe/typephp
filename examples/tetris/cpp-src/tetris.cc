#include <phpx.h>
#include <windows.h>
#include <cstdlib>

using namespace php;

// Game constants
#define BLOCK_SIZE 30
#define BOARD_WIDTH 10
#define BOARD_HEIGHT 20

// Colors for each piece type
static const COLORREF COLORS[7] = {
    RGB(0, 255, 255),   // I - Cyan
    RGB(255, 255, 0),   // O - Yellow
    RGB(128, 0, 128),   // T - Purple
    RGB(0, 255, 0),     // S - Green
    RGB(255, 0, 0),     // Z - Red
    RGB(0, 0, 255),     // J - Blue
    RGB(255, 165, 0)    // L - Orange
};

// Simple game state - must inherit from Box
class TetrisBox : public Box {
public:
    int board[BOARD_HEIGHT][BOARD_WIDTH];
    int score;
    bool gameOver;
    
    TetrisBox() : score(0), gameOver(false) {
        memset(board, 0, sizeof(board));
    }
    
    void reset() {
        score = 0;
        gameOver = false;
        memset(board, 0, sizeof(board));
    }
};

// Create new game instance - returns Box
var php_tetris_new() {
    return {new TetrisBox()};
}

// Reset game
void php_tetris_reset(var box) {
    auto tetris = box.toBox<TetrisBox>();
    tetris->reset();
}

// Get score
Int php_tetris_get_score(var box) {
    auto tetris = box.toBox<TetrisBox>();
    return tetris->score;
}

// Check if game over
Bool php_tetris_is_game_over(var box) {
    auto tetris = box.toBox<TetrisBox>();
    return tetris->gameOver;
}

// Move piece down
Bool php_tetris_move_down(var box) {
    auto tetris = box.toBox<TetrisBox>();
    if (tetris->gameOver) return false;
    // Simplified: just increase score for testing
    tetris->score += 10;
    return true;
}

// Move piece left
Bool php_tetris_move_left(var box) {
    auto tetris = box.toBox<TetrisBox>();
    if (tetris->gameOver) return false;
    return true;
}

// Move piece right  
Bool php_tetris_move_right(var box) {
    auto tetris = box.toBox<TetrisBox>();
    if (tetris->gameOver) return false;
    return true;
}

// Rotate piece
void php_tetris_rotate(var box) {
    auto tetris = box.toBox<TetrisBox>();
    if (!tetris->gameOver) {
        tetris->score += 5;
    }
}

// Hard drop
void php_tetris_hard_drop(var box) {
    auto tetris = box.toBox<TetrisBox>();
    if (!tetris->gameOver) {
        tetris->score += 50;
    }
}

// Get board state
Array php_tetris_get_board(var box) {
    auto tetris = box.toBox<TetrisBox>();
    Array result;
    for (int i = 0; i < BOARD_HEIGHT; i++) {
        Array row;
        for (int j = 0; j < BOARD_WIDTH; j++) {
            row.append(tetris->board[i][j]);
        }
        result.append(row);
    }
    return result;
}

// Get current piece info
Array php_tetris_get_current_piece(var box) {
    Array result;
    result.append(0); // shape
    result.append(5); // x position
    result.append(0); // y position
    result.append(0); // type
    return result;
}

// Create game window
Int php_tetris_create_window(String title) {
    WNDCLASS wc;
    ZeroMemory(&wc, sizeof(wc));
    wc.style = CS_HREDRAW | CS_VREDRAW;
    wc.lpfnWndProc = DefWindowProc;
    wc.hInstance = GetModuleHandle(NULL);
    wc.hCursor = LoadCursor(NULL, IDC_ARROW);
    wc.hbrBackground = (HBRUSH)(COLOR_WINDOW + 1);
    wc.lpszClassName = "TetrisWindow";
    
    RegisterClass(&wc);
    
    HWND hWnd = CreateWindowEx(
        0,
        "TetrisWindow",
        title.data(),
        WS_OVERLAPPEDWINDOW & ~WS_THICKFRAME & ~WS_MAXIMIZEBOX,
        CW_USEDEFAULT,
        CW_USEDEFAULT,
        BLOCK_SIZE * BOARD_WIDTH + 200,
        BLOCK_SIZE * BOARD_HEIGHT + 40,
        NULL,
        NULL,
        GetModuleHandle(NULL),
        NULL
    );
    
    return (Int)hWnd;
}

// Show window
Bool php_tetris_show_window(Int hWnd, Int cmdShow) {
    return ShowWindow((HWND)hWnd, (int)cmdShow);
}

// Render game
void php_tetris_render(var box, Int hWnd) {
    auto tetris = box.toBox<TetrisBox>();
    HDC hdc = GetDC((HWND)hWnd);
    
    // Clear background
    RECT rect;
    rect.left = 0;
    rect.top = 0;
    rect.right = BLOCK_SIZE * BOARD_WIDTH;
    rect.bottom = BLOCK_SIZE * BOARD_HEIGHT;
    FillRect(hdc, &rect, (HBRUSH)GetStockObject(BLACK_BRUSH));
    
    // Draw board
    for (int i = 0; i < BOARD_HEIGHT; i++) {
        for (int j = 0; j < BOARD_WIDTH; j++) {
            if (tetris->board[i][j]) {
                HBRUSH brush = CreateSolidBrush(COLORS[tetris->board[i][j] - 1]);
                RECT blockRect;
                blockRect.left = j * BLOCK_SIZE;
                blockRect.top = i * BLOCK_SIZE;
                blockRect.right = (j + 1) * BLOCK_SIZE;
                blockRect.bottom = (i + 1) * BLOCK_SIZE;
                FillRect(hdc, &blockRect, brush);
                DeleteObject(brush);
            }
        }
    }
    
    ReleaseDC((HWND)hWnd, hdc);
}

// Handle keyboard input
void php_tetris_handle_key(var box, Int keyCode) {
    auto tetris = box.toBox<TetrisBox>();
    // Simplified: just increase score for testing
    tetris->score += 1;
}

// Post quit message
void php_tetris_post_quit(Int exitCode) {
    PostQuitMessage((int)exitCode);
}

// Show message box with UTF-8 support
Int php_tetris_messagebox(Int hWnd, String text, String caption, Int uType) {
    int wtext_len = MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, NULL, 0);
    wchar_t* wtext = new wchar_t[wtext_len];
    MultiByteToWideChar(CP_UTF8, 0, text.data(), -1, wtext, wtext_len);
    
    int wcaption_len = MultiByteToWideChar(CP_UTF8, 0, caption.data(), -1, NULL, 0);
    wchar_t* wcaption = new wchar_t[wcaption_len];
    MultiByteToWideChar(CP_UTF8, 0, caption.data(), -1, wcaption, wcaption_len);
    
    int result = MessageBoxW((HWND)hWnd, wtext, wcaption, (UINT)uType);
    
    delete[] wtext;
    delete[] wcaption;
    return result;
}
