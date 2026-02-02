import React from 'react';
import VideoFeed from './VideoFeed';

/**
 * VideoGallery - Displays multiple participant video feeds in a grid layout
 *
 * @param {Array} participants - Array of LiveKit participant objects
 * @param {Object} localParticipant - Local user's participant object
 * @param {Object} room - LiveKit room object (passed to VideoFeed)
 * @param {string} layout - 'grid' | 'featured' | 'sidebar'
 * @param {Object} featuredParticipant - Participant to feature (for featured layout)
 */
export default function VideoGallery({
  participants = [],
  localParticipant = null,
  room = null,
  layout = 'grid',
  featuredParticipant = null
}) {
  // Combine local and remote participants
  const allParticipants = [
    ...(localParticipant ? [{
      participant: localParticipant,
      name: 'You',
      isLocal: true
    }] : []),
    ...participants.map(p => ({
      participant: p,
      name: p.name || p.identity || 'Unknown',
      isLocal: false
    }))
  ];

  // Calculate grid layout based on participant count
  const getGridCols = (count) => {
    if (count <= 1) return 'grid-cols-1';
    if (count <= 2) return 'grid-cols-2';
    if (count <= 4) return 'grid-cols-2';
    if (count <= 6) return 'grid-cols-3';
    return 'grid-cols-3';
  };

  const getGridRows = (count) => {
    if (count <= 2) return 'grid-rows-1';
    if (count <= 4) return 'grid-rows-2';
    if (count <= 9) return 'grid-rows-3';
    return 'grid-rows-4';
  };

  if (layout === 'featured' && featuredParticipant) {
    // Featured layout: one large video + thumbnails
    const otherParticipants = allParticipants.filter(
      p => p.participant !== featuredParticipant
    );

    return (
      <div className="h-full flex flex-col gap-3">
        {/* Featured video */}
        <div className="flex-1">
          <VideoFeed
            participant={featuredParticipant}
            room={room}
            name={featuredParticipant.identity || 'Unknown'}
            isLocal={false}
            featured={true}
          />
        </div>

        {/* Thumbnail strip */}
        {otherParticipants.length > 0 && (
          <div className="h-full flex gap-2 overflow-x-auto">
            {otherParticipants.map((p, index) => (
              <div key={index} className="w-32 flex-shrink-0">
                <VideoFeed
                  participant={p.participant}
                  room={room}
                  name={p.name}
                  isLocal={p.isLocal}
                  featured={false}
                />
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }

  if (layout === 'sidebar') {
    // Sidebar layout: vertical stack of videos with consistent aspect ratio
    return (
      <div className="space-y-2">
        {allParticipants.map((p, index) => (
          <div key={index} className="aspect-video bg-gray-900 rounded-lg overflow-hidden relative">
            <VideoFeed
              participant={p.participant}
              room={room}
              name={p.name}
              isLocal={p.isLocal}
              featured={true}
            />
          </div>
        ))}
      </div>
    );
  }

  // Grid layout: equal-sized grid
  return (
    <div
      className={`grid ${getGridCols(allParticipants.length)} ${getGridRows(allParticipants.length)} gap-3 h-full`}
    >
      {allParticipants.map((p, index) => (
        <VideoFeed
          key={index}
          participant={p.participant}
          room={room}
          name={p.name}
          isLocal={p.isLocal}
          featured={false}
        />
      ))}
    </div>
  );
}
