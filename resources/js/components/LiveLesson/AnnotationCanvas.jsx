import React, { useRef, useEffect, useState, useCallback } from 'react';
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
    channel,
    disabled = false,
    onStrokeSent = null,
    onStrokeReceived = null
}) => {
    const canvasRef = useRef(null);
    const [isDrawing, setIsDrawing] = useState(false);
    const [currentStroke, setCurrentStroke] = useState([]);
    const [allStrokes, setAllStrokes] = useState([]);
    const [canvasDimensions, setCanvasDimensions] = useState({ width: 0, height: 0 });
    
    // Store the parent container ref for size calculations
    const containerRef = useRef(null);

    /**
     * Initialize canvas dimensions based on parent container
     */
    useEffect(() => {
        const updateCanvasDimensions = () => {
            if (canvasRef.current && canvasRef.current.parentElement) {
                const parent = canvasRef.current.parentElement;
                const rect = parent.getBoundingClientRect();
                
                setCanvasDimensions({
                    width: rect.width,
                    height: rect.height
                });
                
                // Set canvas size
                canvasRef.current.width = rect.width;
                canvasRef.current.height = rect.height;
                
                // Redraw all strokes after resize
                redrawCanvas();
            }
        };

        updateCanvasDimensions();
        window.addEventListener('resize', updateCanvasDimensions);
        
        return () => {
            window.removeEventListener('resize', updateCanvasDimensions);
        };
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
            
            // Add stroke to local state
            setAllStrokes(prev => [...prev, event.stroke_data]);
            
            // Draw the stroke on canvas
            drawReceivedStroke(event.stroke_data);
            
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
    clearCanvas();
};

        // Subscribe to events
        channel.listen('.annotation.stroke', handleAnnotationStroke);
        channel.listen('.annotation.clear', handleAnnotationClear);

        return () => {
            channel.stopListening('.annotation.stroke', handleAnnotationStroke);
            channel.stopListening('.annotation.clear', handleAnnotationClear);
        };
    }, [channel, slideId, onStrokeReceived]);

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
        if (disabled) return;
        
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
        if (!isDrawing || disabled) return;
        
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
            const routeName = userRole === 'teacher' 
                ? 'admin.live-sessions.send-annotation'
                : 'parent.live-sessions.send-annotation';
            
            axios.post(route(routeName, sessionId), {
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
        }, 100), // Throttle to max 10 strokes per second
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
            const strokeData = {
                type: currentTool,
                color: currentColor,
                width: currentWidth,
                points: currentStroke,
                timestamp: new Date().toISOString()
            };
            
            // Add to local strokes
            setAllStrokes(prev => [...prev, strokeData]);
            
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
        
        // Redraw all strokes
        allStrokes.forEach(stroke => {
            drawReceivedStroke(stroke);
        });
    };

    /**
     * Clear canvas and stroke history
     */
    const clearCanvas = () => {
        const ctx = getContext();
        if (!ctx) return;
        
        ctx.clearRect(0, 0, canvasRef.current.width, canvasRef.current.height);

        setAllStrokes([]);
        setCurrentStroke([]);
    };

    return (
        <canvas
            ref={canvasRef}
            className={`absolute inset-0 z-10 ${disabled ? 'pointer-events-none' : 'cursor-crosshair'}`}
            onMouseDown={startDrawing}
            onMouseMove={draw}
            onMouseUp={endDrawing}
            onMouseLeave={endDrawing}
            style={{
                touchAction: 'none'
            }}
        />
    );
};

export default AnnotationCanvas;
