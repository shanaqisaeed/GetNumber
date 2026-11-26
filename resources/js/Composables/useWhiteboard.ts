import { ref, computed, nextTick } from 'vue';
import { __ } from '@/Composables/useTrans';

export type WhiteboardTool = 'pen' | 'highlighter' | 'eraser';

export interface WhiteboardStroke {
    x0: number; // normalized 0..1
    y0: number; // normalized 0..1
    x1: number; // normalized 0..1
    y1: number; // normalized 0..1
    tool?: WhiteboardTool;
    color?: string;
    size?: number;
    userId?: number;
    label?: string;
    phone?: string;
    timestamp?: number;
}

export interface WhiteboardMessage {
    type: 'wb_draw' | 'wb_clear' | 'wb_undo' | 'wb_open' | 'wb_close' | 'wb_sync_req' | 'wb_sync_res' | 'wb_cursor';
    tool?: WhiteboardTool;
    color?: string;
    width?: number;
    from?: [number, number];
    to?: [number, number];
    author?: string;
    userId?: number;
    phone?: string;
    history?: WhiteboardStroke[];
    data?: any;
    nx?: number;
    ny?: number;
}

export interface UseWhiteboardOptions {
    userId?: number;
    userLabel?: string;
    userPhone?: string;
    onBroadcast?: (msg: WhiteboardMessage) => void;
}

export function useWhiteboard(options: UseWhiteboardOptions = {}) {
    const isWhiteboardOpen = ref<boolean>(false);
    const isRemoteWhiteboardOpen = ref<boolean>(false);
    const canvasRef = ref<HTMLCanvasElement | null>(null);

    const whiteboardTool = ref<WhiteboardTool>('pen');
    const whiteboardColor = ref<string>('#ef4444');
    const whiteboardLineWidth = ref<number>(3);
    const lastDrawingAuthor = ref<string>('');

    const strokeHistory = ref<WhiteboardStroke[]>([]);
    const undoStack = ref<string[]>([]);
    const remoteCursor = ref<{ nx: number; ny: number; author: string } | null>(null);

    let isDrawing = false;
    let lastNx = 0;
    let lastNy = 0;

    const whiteboardColors = [
        '#ef4444', '#f97316', '#f59e0b', '#10b981',
        '#06b6d4', '#3b82f6', '#6366f1', '#8b5cf6',
        '#ec4899', '#f43f5e', '#14b8a6', '#84cc16',
        '#0ea5e9', '#64748b', '#000000', '#ffffff',
    ];

    const currentUserId = computed(() => options.userId ?? 0);
    const currentAuthorLabel = computed(() => options.userLabel || __('user.calls.user_badge', { id: currentUserId.value }));
    const currentPhone = computed(() => options.userPhone || '');

    // Convert mouse/touch event into normalized 0..1 coordinates relative to canvas
    const getNormalizedCoords = (e: MouseEvent | Touch, canvas: HTMLCanvasElement): { nx: number; ny: number } => {
        const rect = canvas.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) {
            return { nx: 0, ny: 0 };
        }
        const clientX = 'clientX' in e ? e.clientX : (e as any).pageX;
        const clientY = 'clientY' in e ? e.clientY : (e as any).pageY;
        const x = Math.max(0, Math.min(rect.width, clientX - rect.left));
        const y = Math.max(0, Math.min(rect.height, clientY - rect.top));
        return {
            nx: Number((x / rect.width).toFixed(4)),
            ny: Number((y / rect.height).toFixed(4)),
        };
    };

    // Save current canvas snapshot for Undo
    const saveUndoState = () => {
        const canvas = canvasRef.value;
        if (!canvas) return;
        try {
            const dataUrl = canvas.toDataURL();
            undoStack.value.push(dataUrl);
            if (undoStack.value.length > 25) {
                undoStack.value.shift();
            }
        } catch {}
    };

    // Draw segment on canvas in pixel coordinates mapped from normalized 0..1
    const drawSegment = (
        x0: number,
        y0: number,
        x1: number,
        y1: number,
        tool: WhiteboardTool = 'pen',
        color = '#ef4444',
        width = 3,
        recordHistory = true,
        author = '',
        userId = 0
    ) => {
        const canvas = canvasRef.value;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        ctx.save();
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        const px0 = x0 * canvas.width;
        const py0 = y0 * canvas.height;
        const px1 = x1 * canvas.width;
        const py1 = y1 * canvas.height;

        if (tool === 'eraser') {
            ctx.globalCompositeOperation = 'destination-out';
            ctx.lineWidth = width * 5;
            ctx.strokeStyle = 'rgba(0,0,0,1)';
        } else if (tool === 'highlighter') {
            ctx.globalCompositeOperation = 'source-over';
            ctx.globalAlpha = 0.35;
            ctx.strokeStyle = color;
            ctx.lineWidth = width * 4;
        } else {
            ctx.globalCompositeOperation = 'source-over';
            ctx.globalAlpha = 1.0;
            ctx.strokeStyle = color;
            ctx.lineWidth = width * 1.5;
        }

        ctx.beginPath();
        ctx.moveTo(px0, py0);
        ctx.lineTo(px1, py1);
        ctx.stroke();
        ctx.restore();

        if (recordHistory) {
            strokeHistory.value.push({
                x0,
                y0,
                x1,
                y1,
                tool,
                color,
                size: width,
                userId: userId || currentUserId.value,
                label: author || currentAuthorLabel.value,
                phone: currentPhone.value,
                timestamp: Date.now(),
            });
        }
    };

    // Redraw all strokes from strokeHistory
    const redrawAllStrokes = () => {
        const canvas = canvasRef.value;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        ctx.clearRect(0, 0, canvas.width, canvas.height);
        for (const stroke of strokeHistory.value) {
            drawSegment(
                stroke.x0,
                stroke.y0,
                stroke.x1,
                stroke.y1,
                stroke.tool || 'pen',
                stroke.color || '#ef4444',
                stroke.size || 3,
                false,
                stroke.label,
                stroke.userId
            );
        }
    };

    // Clear Whiteboard
    const clearWhiteboard = (broadcast = true) => {
        const canvas = canvasRef.value;
        if (canvas) {
            const ctx = canvas.getContext('2d');
            if (ctx) {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
            }
        }
        strokeHistory.value = [];
        undoStack.value = [];
        lastDrawingAuthor.value = '';

        if (broadcast && options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_clear',
                userId: currentUserId.value,
                author: currentAuthorLabel.value,
            });
        }
    };

    // Undo last action
    const undo = (broadcast = true) => {
        if (undoStack.value.length === 0) {
            clearWhiteboard(broadcast);
            return;
        }
        const prev = undoStack.value.pop();
        if (!prev) return;

        const canvas = canvasRef.value;
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        const img = new Image();
        img.onload = () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0);
        };
        img.src = prev;

        if (broadcast && options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_undo',
                userId: currentUserId.value,
                author: currentAuthorLabel.value,
            });
        }
    };

    // Canvas Mouse Event Handlers
    const onMouseDown = (e: MouseEvent) => {
        const canvas = canvasRef.value;
        if (!canvas) return;
        isDrawing = true;
        const { nx, ny } = getNormalizedCoords(e, canvas);
        lastNx = nx;
        lastNy = ny;

        saveUndoState();
        lastDrawingAuthor.value = currentAuthorLabel.value;
        drawSegment(nx, ny, nx + 0.0001, ny + 0.0001, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);

        if (options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_draw',
                tool: whiteboardTool.value,
                color: whiteboardColor.value,
                width: whiteboardLineWidth.value,
                from: [nx, ny],
                to: [nx + 0.0001, ny + 0.0001],
                author: currentAuthorLabel.value,
                userId: currentUserId.value,
                phone: currentPhone.value,
            });
        }
    };

    const onMouseMove = (e: MouseEvent) => {
        const canvas = canvasRef.value;
        if (!canvas) return;
        const { nx, ny } = getNormalizedCoords(e, canvas);

        // Broadcast cursor position
        if (options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_cursor',
                nx,
                ny,
                author: currentAuthorLabel.value,
                userId: currentUserId.value,
            });
        }

        if (!isDrawing) return;
        lastDrawingAuthor.value = currentAuthorLabel.value;
        drawSegment(lastNx, lastNy, nx, ny, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);

        if (options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_draw',
                tool: whiteboardTool.value,
                color: whiteboardColor.value,
                width: whiteboardLineWidth.value,
                from: [lastNx, lastNy],
                to: [nx, ny],
                author: currentAuthorLabel.value,
                userId: currentUserId.value,
                phone: currentPhone.value,
            });
        }

        lastNx = nx;
        lastNy = ny;
    };

    const onMouseUp = () => {
        if (!isDrawing) return;
        isDrawing = false;
        saveUndoState();
    };

    // Canvas Touch Event Handlers
    const onTouchStart = (e: TouchEvent) => {
        if (e.touches.length !== 1) return;
        e.preventDefault();
        const canvas = canvasRef.value;
        if (!canvas) return;
        isDrawing = true;
        const { nx, ny } = getNormalizedCoords(e.touches[0], canvas);
        lastNx = nx;
        lastNy = ny;

        saveUndoState();
        lastDrawingAuthor.value = currentAuthorLabel.value;
        drawSegment(nx, ny, nx + 0.0001, ny + 0.0001, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);

        if (options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_draw',
                tool: whiteboardTool.value,
                color: whiteboardColor.value,
                width: whiteboardLineWidth.value,
                from: [nx, ny],
                to: [nx + 0.0001, ny + 0.0001],
                author: currentAuthorLabel.value,
                userId: currentUserId.value,
                phone: currentPhone.value,
            });
        }
    };

    const onTouchMove = (e: TouchEvent) => {
        if (e.touches.length !== 1) return;
        e.preventDefault();
        const canvas = canvasRef.value;
        if (!canvas) return;
        const { nx, ny } = getNormalizedCoords(e.touches[0], canvas);

        if (options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_cursor',
                nx,
                ny,
                author: currentAuthorLabel.value,
                userId: currentUserId.value,
            });
        }

        if (!isDrawing) return;
        lastDrawingAuthor.value = currentAuthorLabel.value;
        drawSegment(lastNx, lastNy, nx, ny, whiteboardTool.value, whiteboardColor.value, whiteboardLineWidth.value);

        if (options.onBroadcast) {
            options.onBroadcast({
                type: 'wb_draw',
                tool: whiteboardTool.value,
                color: whiteboardColor.value,
                width: whiteboardLineWidth.value,
                from: [lastNx, lastNy],
                to: [nx, ny],
                author: currentAuthorLabel.value,
                userId: currentUserId.value,
                phone: currentPhone.value,
            });
        }

        lastNx = nx;
        lastNy = ny;
    };

    const onTouchEnd = (e: TouchEvent) => {
        e.preventDefault();
        if (!isDrawing) return;
        isDrawing = false;
        saveUndoState();
    };

    // Handle inbound remote messages from WebSocket or WebRTC DataChannel
    const handleRemoteMessage = (msg: WhiteboardMessage) => {
        if (!msg || !msg.type) return;

        switch (msg.type) {
            case 'wb_draw':
                if (msg.from && msg.to) {
                    lastDrawingAuthor.value = msg.author || '';
                    drawSegment(
                        msg.from[0],
                        msg.from[1],
                        msg.to[0],
                        msg.to[1],
                        msg.tool || 'pen',
                        msg.color || '#ef4444',
                        msg.width || 3,
                        true,
                        msg.author,
                        msg.userId
                    );
                }
                break;

            case 'wb_clear':
                clearWhiteboard(false);
                break;

            case 'wb_undo':
                undo(false);
                break;

            case 'wb_open':
                isRemoteWhiteboardOpen.value = true;
                isWhiteboardOpen.value = true;
                setupCanvas();
                break;

            case 'wb_close':
                isRemoteWhiteboardOpen.value = false;
                break;

            case 'wb_cursor':
                if (typeof msg.nx === 'number' && typeof msg.ny === 'number') {
                    remoteCursor.value = {
                        nx: msg.nx,
                        ny: msg.ny,
                        author: msg.author || '',
                    };
                }
                break;

            case 'wb_sync_req':
                if (options.onBroadcast && strokeHistory.value.length > 0) {
                    options.onBroadcast({
                        type: 'wb_sync_res',
                        history: strokeHistory.value,
                        userId: currentUserId.value,
                    });
                }
                break;

            case 'wb_sync_res':
                if (Array.isArray(msg.history)) {
                    strokeHistory.value = msg.history;
                    redrawAllStrokes();
                }
                break;
        }
    };

    // Canvas setup with 16:9 ratio and high-DPI scaling
    const setupCanvas = () => {
        nextTick(() => {
            const canvas = canvasRef.value;
            if (!canvas) return;
            const rect = canvas.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return;

            const dpr = Math.min(typeof window !== 'undefined' ? window.devicePixelRatio || 1 : 1, 2);
            canvas.width = Math.round(rect.width * dpr);
            canvas.height = Math.round(rect.height * dpr);

            redrawAllStrokes();
        });
    };

    const openWhiteboard = (fromRemote = false) => {
        isWhiteboardOpen.value = true;
        setupCanvas();
        if (!fromRemote && options.onBroadcast) {
            options.onBroadcast({ type: 'wb_open', userId: currentUserId.value });
        } else if (fromRemote && options.onBroadcast) {
            setTimeout(() => {
                options.onBroadcast?.({ type: 'wb_sync_req', userId: currentUserId.value });
            }, 300);
        }
    };

    const closeWhiteboard = () => {
        isWhiteboardOpen.value = false;
        isRemoteWhiteboardOpen.value = false;
        if (options.onBroadcast) {
            options.onBroadcast({ type: 'wb_close', userId: currentUserId.value });
        }
    };

    const toggleWhiteboard = () => {
        if (isWhiteboardOpen.value) {
            closeWhiteboard();
        } else {
            openWhiteboard();
        }
    };

    return {
        isWhiteboardOpen,
        isRemoteWhiteboardOpen,
        canvasRef,
        whiteboardTool,
        whiteboardColor,
        whiteboardLineWidth,
        whiteboardColors,
        strokeHistory,
        undoStack,
        lastDrawingAuthor,
        remoteCursor,
        setupCanvas,
        openWhiteboard,
        closeWhiteboard,
        toggleWhiteboard,
        clearWhiteboard,
        undo,
        drawSegment,
        redrawAllStrokes,
        onMouseDown,
        onMouseMove,
        onMouseUp,
        onTouchStart,
        onTouchMove,
        onTouchEnd,
        handleRemoteMessage,
    };
}
