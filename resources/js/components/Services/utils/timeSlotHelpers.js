/**
 * Time Slot Helper Utilities
 * For flexible service selection UI enhancements
 */

/**
 * Get time of day category for a given time
 */
export const getTimeOfDay = (startTime) => {
  if (!startTime) return 'other';
  const hour = new Date(startTime).getHours();
  if (hour >= 6 && hour < 12) return 'morning';
  if (hour >= 12 && hour < 17) return 'afternoon';
  if (hour >= 17 && hour < 21) return 'evening';
  return 'other';
};

/**
 * Time filter options
 */
export const TIME_FILTERS = [
  { id: 'all', label: 'All Times', sublabel: '', icon: '🕐' },
  { id: 'morning', label: 'Morning', sublabel: '6am - 12pm', icon: '🌅' },
  { id: 'afternoon', label: 'Afternoon', sublabel: '12pm - 5pm', icon: '☀️' },
  { id: 'evening', label: 'Evening', sublabel: '5pm - 9pm', icon: '🌙' },
];

/**
 * Get availability status with color and text
 */
export const getAvailabilityStatus = (item) => {
  const enrollmentLimit = item.enrollment_limit || item.max_enrollments;
  const currentEnrollments = item.current_enrollments || 0;
  
  if (!enrollmentLimit) {
    return { 
      status: 'unlimited', 
      color: 'blue', 
      text: 'Unlimited',
      bgColor: 'bg-blue-100',
      textColor: 'text-blue-800',
      borderColor: 'border-blue-200',
      ringColor: 'ring-blue-500'
    };
  }
  
  const remaining = enrollmentLimit - currentEnrollments;
  const percentage = (remaining / enrollmentLimit) * 100;
  
  if (remaining <= 0) {
    return { 
      status: 'full', 
      color: 'red', 
      text: 'Full',
      bgColor: 'bg-red-100',
      textColor: 'text-red-800',
      borderColor: 'border-red-200',
      ringColor: 'ring-red-500'
    };
  }
  if (percentage <= 20) {
    return { 
      status: 'almost-full', 
      color: 'orange', 
      text: `${remaining} left!`,
      bgColor: 'bg-orange-100',
      textColor: 'text-orange-800',
      borderColor: 'border-orange-200',
      ringColor: 'ring-orange-500'
    };
  }
  if (percentage <= 50) {
    return { 
      status: 'limited', 
      color: 'yellow', 
      text: `${remaining} spots`,
      bgColor: 'bg-yellow-100',
      textColor: 'text-yellow-800',
      borderColor: 'border-yellow-200',
      ringColor: 'ring-yellow-500'
    };
  }
  return { 
    status: 'available', 
    color: 'green', 
    text: `${remaining} spots`,
    bgColor: 'bg-green-100',
    textColor: 'text-green-800',
    borderColor: 'border-green-200',
    ringColor: 'ring-green-500'
  };
};

/**
 * Group items by date
 */
export const groupByDate = (items, dateField = 'start_time') => {
  const groups = {};
  
  items.forEach(item => {
    const dateValue = item[dateField];
    if (!dateValue) {
      const key = 'No Date Set';
      if (!groups[key]) groups[key] = { date: null, items: [] };
      groups[key].items.push(item);
      return;
    }
    
    const date = new Date(dateValue);
    const dateKey = date.toDateString();
    const formattedDate = date.toLocaleDateString('en-GB', {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
    
    if (!groups[dateKey]) {
      groups[dateKey] = { 
        date: date,
        formattedDate,
        items: [] 
      };
    }
    groups[dateKey].items.push(item);
  });
  
  // Sort groups by date
  const sortedKeys = Object.keys(groups).sort((a, b) => {
    if (a === 'No Date Set') return 1;
    if (b === 'No Date Set') return -1;
    return new Date(a) - new Date(b);
  });
  
  return sortedKeys.map(key => ({
    key,
    ...groups[key]
  }));
};

/**
 * Format time for display
 */
export const formatTime = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toLocaleTimeString('en-GB', {
    hour: '2-digit',
    minute: '2-digit'
  });
};

/**
 * Format date for display
 */
export const formatDate = (dateString, options = {}) => {
  if (!dateString) return '';
  const defaultOptions = {
    weekday: 'short',
    month: 'short',
    day: 'numeric'
  };
  return new Date(dateString).toLocaleDateString('en-GB', { ...defaultOptions, ...options });
};

/**
 * Check if two dates are on the same day
 */
export const isSameDay = (date1, date2) => {
  if (!date1 || !date2) return false;
  const d1 = new Date(date1);
  const d2 = new Date(date2);
  return d1.toDateString() === d2.toDateString();
};

/**
 * Format date-time range for display
 * Shows end date only if different from start date
 * 
 * Same day: "Mon, Dec 16 • 9:00 AM - 5:00 PM"
 * Different day: "Mon, Dec 16 9:00 AM - Wed, Dec 19 5:00 PM"
 */
export const formatDateTimeRange = (startTime, endTime) => {
  if (!startTime) return { date: '', timeRange: '' };
  
  const startDate = formatDate(startTime);
  const startTimeStr = formatTime(startTime);
  
  if (!endTime) {
    return {
      date: startDate,
      timeRange: startTimeStr,
      fullDisplay: `${startDate} • ${startTimeStr}`
    };
  }
  
  const endTimeStr = formatTime(endTime);
  
  if (isSameDay(startTime, endTime)) {
    // Same day: "Mon, Dec 16 • 9:00 - 17:00"
    return {
      date: startDate,
      timeRange: `${startTimeStr} - ${endTimeStr}`,
      fullDisplay: `${startDate} • ${startTimeStr} - ${endTimeStr}`,
      isSameDay: true
    };
  } else {
    // Different day: "Mon, Dec 16 9:00 - Wed, Dec 19 17:00"
    const endDate = formatDate(endTime);
    return {
      date: startDate,
      endDate: endDate,
      timeRange: `${startTimeStr} - ${endDate} ${endTimeStr}`,
      fullDisplay: `${startDate} ${startTimeStr} - ${endDate} ${endTimeStr}`,
      isSameDay: false
    };
  }
};

/**
 * Calculate duration between two times
 */
export const calculateDuration = (startTime, endTime) => {
  if (!startTime || !endTime) return null;
  
  const start = new Date(startTime);
  const end = new Date(endTime);
  const diffMs = end - start;
  const diffMins = Math.round(diffMs / 60000);
  
  if (diffMins < 60) return `${diffMins} mins`;
  const hours = Math.floor(diffMins / 60);
  const mins = diffMins % 60;
  return mins > 0 ? `${hours}h ${mins}m` : `${hours} hour${hours > 1 ? 's' : ''}`;
};

/**
 * Get dates with sessions for calendar view
 */
export const getDatesWithSessions = (items, dateField = 'start_time') => {
  const dates = {};
  items.forEach(item => {
    const dateValue = item[dateField];
    if (!dateValue) return;
    
    const dateKey = new Date(dateValue).toDateString();
    if (!dates[dateKey]) {
      dates[dateKey] = { count: 0, available: 0 };
    }
    dates[dateKey].count++;
    
    const status = getAvailabilityStatus(item);
    if (status.status !== 'full') {
      dates[dateKey].available++;
    }
  });
  return dates;
};
