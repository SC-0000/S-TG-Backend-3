import React from 'react';
import { PencilIcon, SparklesIcon, TrashIcon, XMarkIcon } from '@heroicons/react/24/outline';

/**
 * AnnotationToolbar Component
 * 
 * Toolbar UI for annotation controls with tool selection, colors, and widths.
 */
const AnnotationToolbar = ({
    currentTool,
    currentColor,
    currentWidth,
    onToolChange,
    onColorChange,
    onWidthChange,
    onClearAll,
    disabled = false
}) => {
    // Available tools
    const tools = [
        { id: 'pen', label: 'Pen', icon: PencilIcon },
        { id: 'highlighter', label: 'Highlighter', icon: SparklesIcon },
        { id: 'eraser', label: 'Eraser', icon: TrashIcon }
    ];

    // Available colors
    const colors = [
        { id: 'black', hex: '#000000', label: 'Black' },
        { id: 'red', hex: '#EF4444', label: 'Red' },
        { id: 'blue', hex: '#3B82F6', label: 'Blue' },
        { id: 'green', hex: '#10B981', label: 'Green' },
        { id: 'yellow', hex: '#FBBF24', label: 'Yellow' },
        { id: 'white', hex: '#FFFFFF', label: 'White' }
    ];

    // Available line widths (in pixels)
    const widths = [
        { id: 'thin', value: 2, label: 'Thin' },
        { id: 'medium', value: 5, label: 'Medium' },
        { id: 'thick', value: 10, label: 'Thick' }
    ];

    return (
        <div className="bg-white border border-gray-200 rounded-lg shadow-sm p-3 mb-4">
            <div className="flex items-center gap-6 flex-wrap">
                {/* Tools Section */}
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-700 mr-2">Tools:</span>
                    {tools.map(tool => {
                        const Icon = tool.icon;
                        const isActive = currentTool === tool.id;
                        
                        return (
                            <button
                                key={tool.id}
                                onClick={() => onToolChange(tool.id)}
                                disabled={disabled}
                                className={`
                                    p-2 rounded-md border-2 transition-all
                                    ${isActive 
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700' 
                                        : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50'
                                    }
                                    ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}
                                `}
                                title={tool.label}
                            >
                                <Icon className="w-5 h-5" />
                            </button>
                        );
                    })}
                </div>

                {/* Divider */}
                <div className="h-8 w-px bg-gray-300" />

                {/* Colors Section */}
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-700 mr-2">Colors:</span>
                    {colors.map(color => {
                        const isActive = currentColor === color.hex;
                        
                        return (
                            <button
                                key={color.id}
                                onClick={() => onColorChange(color.hex)}
                                disabled={disabled || currentTool === 'eraser'}
                                className={`
                                    w-8 h-8 rounded-md border-2 transition-all
                                    ${isActive 
                                        ? 'border-indigo-500 scale-110' 
                                        : 'border-gray-300 hover:scale-105'
                                    }
                                    ${disabled || currentTool === 'eraser' ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}
                                    ${color.id === 'white' ? 'shadow-inner' : ''}
                                `}
                                style={{ 
                                    backgroundColor: color.hex,
                                    boxShadow: color.id === 'white' ? 'inset 0 0 0 1px #e5e7eb' : undefined
                                }}
                                title={color.label}
                            />
                        );
                    })}
                </div>

                {/* Divider */}
                <div className="h-8 w-px bg-gray-300" />

                {/* Width Section */}
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-700 mr-2">Size:</span>
                    {widths.map(width => {
                        const isActive = currentWidth === width.value;
                        
                        return (
                            <button
                                key={width.id}
                                onClick={() => onWidthChange(width.value)}
                                disabled={disabled}
                                className={`
                                    px-3 py-1.5 rounded-md border-2 text-sm font-medium transition-all
                                    ${isActive 
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700' 
                                        : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50'
                                    }
                                    ${disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}
                                `}
                                title={width.label}
                            >
                                {width.label}
                            </button>
                        );
                    })}
                </div>

                {/* Divider */}
                <div className="h-8 w-px bg-gray-300" />

                {/* Clear All Button */}
                <button
                    onClick={onClearAll}
                    disabled={disabled}
                    className={`
                        flex items-center gap-2 px-3 py-1.5 rounded-md border-2 
                        border-red-200 bg-white text-red-600 text-sm font-medium
                        transition-all
                        ${disabled 
                            ? 'opacity-50 cursor-not-allowed' 
                            : 'hover:border-red-300 hover:bg-red-50 cursor-pointer'
                        }
                    `}
                    title="Clear all annotations"
                >
                    <XMarkIcon className="w-4 h-4" />
                    Clear All
                </button>
            </div>

            {/* Status Indicator */}
            <div className="mt-3 pt-3 border-t border-gray-200">
                <div className="flex items-center gap-2 text-sm text-gray-600">
                    <div className="flex items-center gap-2">
                        <span className="font-medium">Current:</span>
                        <span className="capitalize">{currentTool}</span>
                        {currentTool !== 'eraser' && (
                            <>
                                <span>•</span>
                                <div 
                                    className="w-4 h-4 rounded border border-gray-300"
                                    style={{ backgroundColor: currentColor }}
                                />
                                <span>•</span>
                                <span>
                                    {widths.find(w => w.value === currentWidth)?.label || 'Medium'}
                                </span>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default AnnotationToolbar;
