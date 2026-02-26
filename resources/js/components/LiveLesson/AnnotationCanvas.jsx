import React, { useRef, useEffect, useState, useCallback, useMemo } from 'react';
import axios from 'axios';
import { throttle } from 'lodash';

/**
 * AnnotationCanvas Component
 * 
 * Real-time collaborative annotation canvas overlay for live lesson slides.
 * Supports pen, highlighter, and eraser tools with multiple colors and widths.
 */
const AnnotationCanvas = ({
    sessionId,
    slideId,
    currentTool,
    currentColor,
    currentWidth,
    userRole,
    userId = null,
    userName = null,
    channel,
    disabled = false,
    canDraw = true,
    onStrokeSent = null,
    onStrokeReceived = null
}) => {
    const canvasRef = useRef(null);
    const [isDrawing, setIsDrawing] = useState(false);
    const [currentStroke, setCurrentStroke] = useState([]);
    const strokesRef = useRef(new Map());
    const [renderTick, setRenderTick] = useState(0);
    const userColorsRef = useRef(new Map());
    const userInfoRef = useRef(new Map());
    const [legendUsers, setLegendUsers] = useState([]);
    const [canvasDimensions, setCanvasDimensions] = useState({ width: 0, height: 0 });
    
    // Store the parent container ref for size calculations
    const containerRef = useRef(null);

    /**
     * Initialize canvas dimensions based on parent container
     */
    useEffect(() => {
        const updateCanvasDimensions = () => {
            if (!canvasRef.current || !canvasRef.current.parentElement) return;

            const parent = canvasRef.current.parentElement;
            const rect = parent.getBoundingClientRect();
            const width = Math.max(1, Math.floor(rect.width));
            const height = Math.max(1, Math.floor(rect.height));

            setCanvasDimensions({ width, height });

            // Set canvas size (drawing buffer)
            canvasRef.current.width = width;
            canvasRef.current.height = height;

            // Ensure canvas visually fills the container
            canvasRef.current.style.width = '100%';
            canvasRef.current.style.height = '100%';

            // Redraw all strokes after resize
            redrawCanvas();
        };

        const parent = canvasRef.current?.parentElement;
        if (!parent) return;

        // Initial measurement after layout
        const rafId = requestAnimationFrame(updateCanvasDimensions);

        // Observe size changes
        const resizeObserver = new ResizeObserver(() => updateCanvasDimensions());
        resizeObserver.observe(parent);

        // Fallback on window resize
        window.addEventListener('resize', updateCanvasDimensions);

        return () => {
            cancelAnimationFrame(rafId);
            resizeObserver.disconnect();
            window.removeEventListener('resize', updateCanvasDimensions);
        };
    }, [slideId]);

    const canInteract = useMemo(() => {
        return !disabled && canDraw;
    }, [disabled, canDraw]);

    const getUserColor = useCallback((id) => {
        if (id === null || id === undefined) return '#111827';
        const key = String(id);
        if (userColorsRef.current.has(key)) {
            return userColorsRef.current.get(key);
        }
        let hash = 0;
        for (let i = 0; i < key.length; i += 1) {
            hash = (hash * 31 + key.charCodeAt(i)) % 360;
        }
        const color = `hsl(${hash}, 70%, 45%)`;
        userColorsRef.current.set(key, color);
        return color;
    }, []);

    const upsertUserInfo = useCallback((info) => {
        if (!info || info.userId === null || info.userId === undefined) return;
        const key = String(info.userId);
        const existing = userInfoRef.current.get(key) || {};
        const next = {
            userId: key,
            userName: info.userName || existing.userName || `User ${key}`,
            userRole: info.userRole || existing.userRole || 'student',
            color: info.color || existing.color || getUserColor(key),
        };
        userInfoRef.current.set(key, next);
        setLegendUsers(Array.from(userInfoRef.current.values()));
    }, [getUserColor]);

    const addStrokeForUser = useCallback((id, strokeData) => {
        const key = String(id);
        const existing = strokesRef.current.get(key) || [];
        existing.push(strokeData);
        strokesRef.current.set(key, existing);
        setRenderTick(t => t + 1);
    }, []);

    const clearUserStrokes = useCallback((id) => {
        if (id === null || id === undefined) return;
        const key = String(id);
        strokesRef.current.delete(key);
        setRenderTick(t => t + 1);
    }, []);

    const clearAllStrokes = useCallback(() => {
        strokesRef.current.clear();
        setRenderTick(t => t + 1);
    }, []);

    /**
     * Listen for annotation events from WebSocket
     */
    useEffect(() => {
        if (!channel) return;

        const handleAnnotationStroke = (event) => {
            console.log('[AnnotationCanvas] Received annotation stroke', event);
            
            // Only process strokes for current slide
            if (event.slide_id !== slideId) return;
            
            const eventUserId = event.user_id ?? event.stroke_data?.user_id;
            const eventUserName = event.user_name ?? event.stroke_data?.user_name;
            const eventUserRole = event.user_role ?? event.stroke_data?.user_role;
            const eventUserColor = event.stroke_data?.user_color;

            upsertUserInfo({
                userId: eventUserId,
                userName: eventUserName,
                userRole: eventUserRole,
                color: eventUserColor,
            });

            addStrokeForUser(eventUserId, event.stroke_data);
            
            // Callback
            if (onStrokeReceived) {
                onStrokeReceived(event);
            }
        };

        const handleAnnotationClear = (event) => {
            console.log('[AnnotationCanvas] ✅ Received annotation clear EVENT!', {
                event,
                event_slide_id: event.slide_id,
                current_slideId: slideId,
                matches: event.slide_id === slideId
            });
            
            // Only clear for current slide
            if (event.slide_id !== slideId) {
                console.warn('[AnnotationCanvas] ❌ Slide ID mismatch, ignoring clear');
                return;
            }
            
            console.log('[AnnotationCanvas] ✅ Clearing canvas NOW!');
            const targetUserId = event.user_id ?? null;
            if (targetUserId) {
                clearUserStrokes(targetUserId);
            } else {
                clearAllStrokes();
            }
        };

        // Subscribe to events
        channel.listen('.annotation.stroke', handleAnnotationStroke);
        channel.listen('.annotation.clear', handleAnnotationClear);

        return () => {
            channel.stopListening('.annotation.stroke', handleAnnotationStroke);
            channel.stopListening('.annotation.clear', handleAnnotationClear);
        };
    }, [channel, slideId, onStrokeReceived, addStrokeForUser, clearUserStrokes, clearAllStrokes, upsertUserInfo]);

    /**
     * Clear canvas and all strokes when slide changes
     */
    useEffect(() => {
        clearCanvas();
    }, [slideId]);

    /**
     * Get canvas context with proper settings
     */
    const getContext = useCallback(() => {
        if (!canvasRef.current) return null;
        
        const ctx = canvasRef.current.getContext('2d');
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        
        return ctx;
    }, []);

    /**
     * Convert mouse event to canvas coordinates
     */
    const getCanvasCoordinates = (e) => {
        const canvas = canvasRef.current;
        if (!canvas) return { x: 0, y: 0 };
        
        const rect = canvas.getBoundingClientRect();
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    };

    /**
     * Start drawing
     */
    const startDrawing = (e) => {
        if (!canInteract) return;
        
        setIsDrawing(true);
        const coords = getCanvasCoordinates(e);
        setCurrentStroke([coords]);
        
        const ctx = getContext();
        if (ctx) {
            ctx.beginPath();
            ctx.moveTo(coords.x, coords.y);
        }
    };

    /**
     * Draw on canvas
     */
    const draw = (e) => {
        if (!isDrawing || !canInteract) return;
        
        const coords = getCanvasCoordinates(e);
        setCurrentStroke(prev => [...prev, coords]);
        
        const ctx = getContext();
        if (!ctx) return;
        
        // Set drawing properties based on tool
        if (currentTool === 'eraser') {
            ctx.globalCompositeOperation = 'destination-out';
            ctx.strokeStyle = 'rgba(0,0,0,1)';
            ctx.lineWidth = currentWidth * 2; // Eraser is wider
        } else if (currentTool === 'highlighter') {
            ctx.globalCompositeOperation = 'source-over';
            ctx.strokeStyle = currentColor;
            ctx.globalAlpha = 0.3;
            ctx.lineWidth = currentWidth * 3; // Highlighter is widest
        } else {
            // Pen
            ctx.globalCompositeOperation = 'source-over';
            ctx.strokeStyle = currentColor;
            ctx.globalAlpha = 1.0;
            ctx.lineWidth = currentWidth;
        }
        
        ctx.lineTo(coords.x, coords.y);
        ctx.stroke();
    };

    /**
     * Throttled function to send stroke data to backend
     */
    const sendStrokeData = useCallback(
        throttle((strokeData) => {
            axios.post(`/live-sessions/${sessionId}/annotation`, {
                slide_id: slideId,
                stroke_data: strokeData,
                user_role: userRole
            })
            .then(() => {
                console.log('[AnnotationCanvas] Stroke sent successfully');
                if (onStrokeSent) {
                    onStrokeSent(strokeData);
                }
            })
            .catch(error => {
                console.error('[AnnotationCanvas] Failed to send stroke:', error);
            });
        }, 60), // Throttle to ~16 strokes per second
        [sessionId, slideId, userRole, onStrokeSent]
    );

    /**
     * End drawing and broadcast stroke
     */
    const endDrawing = () => {
        if (!isDrawing) return;
        
        setIsDrawing(false);
        
        const ctx = getContext();
        if (ctx) {
            ctx.closePath();
            ctx.globalCompositeOperation = 'source-over';
            ctx.globalAlpha = 1.0;
        }
        
        // Prepare stroke data
        if (currentStroke.length > 0) {
            const effectiveUserId = userId ?? 'unknown';
            const effectiveUserName = userName || (userRole === 'teacher' ? 'Teacher' : 'Student');
            const effectiveColor =
                userRole === 'teacher'
                    ? currentColor
                    : getUserColor(effectiveUserId);
            const strokeData = {
                type: currentTool,
                color: effectiveColor,
                width: currentWidth,
                points: currentStroke,
                timestamp: new Date().toISOString(),
                user_id: effectiveUserId,
                user_name: effectiveUserName,
                user_role: userRole,
                user_color: effectiveColor
            };
            
            upsertUserInfo({
                userId: effectiveUserId,
                userName: effectiveUserName,
                userRole,
                color: effectiveColor,
            });

            // Add to local strokes
            addStrokeForUser(effectiveUserId, strokeData);
            
            // Send to backend
            sendStrokeData(strokeData);
        }
        
        setCurrentStroke([]);
    };

    /**
     * Draw a received stroke on the canvas
     */
    const drawReceivedStroke = (strokeData) => {
        const ctx = getContext();
        if (!ctx || !strokeData.points || strokeData.points.length === 0) return;
        
        ctx.beginPath();
        ctx.moveTo(strokeData.points[0].x, strokeData.points[0].y);
        
        // Set drawing properties
        if (strokeData.type === 'eraser') {
            ctx.globalCompositeOperation = 'destination-out';
            ctx.strokeStyle = 'rgba(0,0,0,1)';
            ctx.lineWidth = strokeData.width * 2;
        } else if (strokeData.type === 'highlighter') {
            ctx.globalCompositeOperation = 'source-over';
            ctx.strokeStyle = strokeData.color;
            ctx.globalAlpha = 0.3;
            ctx.lineWidth = strokeData.width * 3;
        } else {
            // Pen
            ctx.globalCompositeOperation = 'source-over';
            ctx.strokeStyle = strokeData.color;
            ctx.globalAlpha = 1.0;
            ctx.lineWidth = strokeData.width;
        }
        
        // Draw the stroke
        for (let i = 1; i < strokeData.points.length; i++) {
            ctx.lineTo(strokeData.points[i].x, strokeData.points[i].y);
        }
        
        ctx.stroke();
        ctx.closePath();
        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1.0;
    };

    /**
     * Redraw entire canvas from stroke history
     */
    const redrawCanvas = () => {
        const ctx = getContext();
        if (!ctx) return;
        
        // Clear canvas
        ctx.clearRect(0, 0, canvasDimensions.width, canvasDimensions.height);
        
        // Redraw all strokes from all users
        strokesRef.current.forEach((strokes) => {
            strokes.forEach(stroke => {
                drawReceivedStroke(stroke);
            });
        });
    };

    /**
     * Clear canvas and stroke history
     */
    const clearCanvas = () => {
        const ctx = getContext();
        if (!ctx) return;
        
        ctx.clearRect(0, 0, canvasRef.current.width, canvasRef.current.height);
        clearAllStrokes();
        setCurrentStroke([]);
    };

    useEffect(() => {
        redrawCanvas();
    }, [renderTick]);

    useEffect(() => {
        if (userId !== null && userId !== undefined) {
            upsertUserInfo({
                userId,
                userName: userName || (userRole === 'teacher' ? 'Teacher' : 'Student'),
                userRole,
                color: getUserColor(userId),
            });
        }
    }, [userId, userName, userRole, getUserColor, upsertUserInfo]);

    return (
        <div className="absolute inset-0 z-10">
            <canvas
                ref={canvasRef}
                className={`absolute inset-0 ${canInteract ? 'cursor-crosshair' : 'pointer-events-none'}`}
                onMouseDown={startDrawing}
                onMouseMove={draw}
                onMouseUp={endDrawing}
                onMouseLeave={endDrawing}
                style={{
                    touchAction: 'none'
                }}
            />
            {legendUsers.length > 0 && (
                <div className="absolute top-2 right-2 bg-white/80 backdrop-blur-sm rounded-md px-2 py-1 text-xs text-gray-800 space-y-1 pointer-events-none">
                    {legendUsers.map((u) => (
                        <div key={u.userId} className="flex items-center gap-2">
                            <span className="inline-block w-2.5 h-2.5 rounded-full" style={{ backgroundColor: u.color }} />
                            <span>{u.userName || `User ${u.userId}`}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
};

export default AnnotationCanvas;
