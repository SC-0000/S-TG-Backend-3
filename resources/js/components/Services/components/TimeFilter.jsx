import React from 'react';
import { TIME_FILTERS } from '../utils/timeSlotHelpers';

/**
 * TimeFilter Component
 * Filter buttons for time of day selection
 */
export default function TimeFilter({ 
  selectedFilter, 
  onFilterChange,
  className = '' 
}) {
  return (
    <div className={`flex flex-wrap gap-2 ${className}`}>
      {TIME_FILTERS.map(filter => (
        <button
          key={filter.id}
          type="button"
          onClick={() => onFilterChange(filter.id)}
          className={`
            inline-flex items-center px-3 py-2 rounded-lg border text-sm font-medium
            transition-all duration-200
            ${selectedFilter === filter.id
              ? 'bg-blue-600 text-white border-blue-600 shadow-sm'
              : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50 hover:border-gray-400'
            }
          `}
        >
          <span className="mr-2 text-base">{filter.icon}</span>
          <span>{filter.label}</span>
          {filter.sublabel && (
            <span className={`ml-1 text-xs ${
              selectedFilter === filter.id ? 'text-blue-200' : 'text-gray-500'
            }`}>
              ({filter.sublabel})
            </span>
          )}
        </button>
      ))}
    </div>
  );
}

/**
 * Compact TimeFilter for mobile/smaller spaces
 */
export function TimeFilterCompact({ 
  selectedFilter, 
  onFilterChange,
  className = '' 
}) {
  return (
    <div className={`flex rounded-lg border border-gray-300 overflow-hidden ${className}`}>
      {TIME_FILTERS.map((filter, index) => (
        <button
          key={filter.id}
          type="button"
          onClick={() => onFilterChange(filter.id)}
          className={`
            flex-1 px-2 py-1.5 text-xs font-medium transition-colors
            ${index > 0 ? 'border-l border-gray-300' : ''}
            ${selectedFilter === filter.id
              ? 'bg-blue-600 text-white'
              : 'bg-white text-gray-600 hover:bg-gray-50'
            }
          `}
          title={filter.sublabel || filter.label}
        >
          <span className="text-base">{filter.icon}</span>
        </button>
      ))}
    </div>
  );
}
