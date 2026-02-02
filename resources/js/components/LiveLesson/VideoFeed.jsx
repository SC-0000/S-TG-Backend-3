import React, { useEffect, useRef, useState } from 'react';
import { FaMicrophone, FaMicrophoneSlash, FaVideo, FaVideoSlash } from 'react-icons/fa';

/**
 * VideoFeed - Displays a single participant's video/audio feed
 *
 * @param {Object} participant - LiveKit participant object
 * @param {Object} room - LiveKit room object (needed for room-level events)
 * @param {string} name - Display name
 * @param {boolean} isLocal - Whether this is the local user's feed
 * @param {boolean} featured - Whether to display in featured/large mode
 */
export default function VideoFeed({ participant, room, name, isLocal = false, featured = false }) {
  const videoRef = useRef(null);
  const audioRef = useRef(null);
  const [videoTrack, setVideoTrack] = useState(null);
  const [audioTrack, setAudioTrack] = useState(null);
  const [isSpeaking, setIsSpeaking] = useState(false);
  const [localVideoEnabled, setLocalVideoEnabled] = useState(false);
  const [localAudioEnabled, setLocalAudioEnabled] = useState(false);

  // For local participant, track camera/mic state directly
  useEffect(() => {
    if (!participant || !isLocal) return;

    const updateLocalTracks = () => {
      setLocalVideoEnabled(participant.isCameraEnabled);
      setLocalAudioEnabled(participant.isMicrophoneEnabled);
    };

    // Update immediately
    updateLocalTracks();

    // Listen for changes
    participant.on('localTrackPublished', updateLocalTracks);
    participant.on('localTrackUnpublished', updateLocalTracks);

    return () => {
      participant.off('localTrackPublished', updateLocalTracks);
      participant.off('localTrackUnpublished', updateLocalTracks);
    };
  }, [participant, isLocal]);

  // Subscribe to participant tracks
  useEffect(() => {
    if (!participant) return;

    console.log('[VideoFeed] 🔵 MOUNT - Setting up track listeners', {
      name,
      participantSid: participant.sid,
      participantIdentity: participant.identity,
      isLocal,
      hasRoom: !!room
    });

    const handleTrackSubscribed = (track, publication, participant) => {
      // ✅ When called from room-level event, we get participant as 3rd arg
      // Filter to only handle tracks for THIS participant
      if (publication && publication.participant && publication.participant.sid !== participant.sid) {
        return; // Not for this participant
      }

      // ✅ NEW: Enhanced logging with codec information
      console.log('[VideoFeed] 🎯 EVENT: trackSubscribed', {
        name,
        trackKind: track.kind,
        trackSid: track.sid,
        timestamp: new Date().toISOString()
      });

      // ✅ CRITICAL: Log codec details for audio tracks (to verify opus is being used)
      if (track.kind === 'audio') {
        console.log('[VideoFeed] 🎵 AUDIO TRACK CODEC INFO:', {
          name,
          codec: track.codec,
          mimeType: track.mimeType,
          source: publication?.source,
          trackSid: track.sid,
          // Additional codec metadata if available
          codecParameters: track.codecParameters,
          timestamp: new Date().toISOString()
        });

        // ✅ WARNING: Check if RED wrapper is being used (should be false)
        if (track.mimeType && track.mimeType.includes('red')) {
          console.warn('[VideoFeed] ⚠️⚠️⚠️ RED CODEC DETECTED! This may cause clock drift issues:', {
            name,
            mimeType: track.mimeType,
            codec: track.codec
          });
        } else if (track.codec === 'opus' || (track.mimeType && track.mimeType.includes('opus'))) {
          console.log('[VideoFeed] ✅ OPUS codec confirmed (good for sync):', {
            name,
            codec: track.codec,
            mimeType: track.mimeType
          });
        }
      }

      if (track.kind === 'video') {
        setVideoTrack(track);
      } else if (track.kind === 'audio') {
        setAudioTrack(track);
      }
    };

    const handleTrackUnsubscribed = (track) => {
      console.log('[VideoFeed] ❌ EVENT: trackUnsubscribed', {
        name,
        trackKind: track.kind
      });

      if (track.kind === 'video') {
        setVideoTrack(null);
      } else if (track.kind === 'audio') {
        setAudioTrack(null);
      }
    };

    const handleIsSpeakingChanged = (speaking) => {
      setIsSpeaking(speaking);
    };

    // ✅ Handle track published events
    const handleTrackPublished = (publication) => {
      console.log('[VideoFeed] 🆕 EVENT: trackPublished', {
        name,
        trackKind: publication.kind,
        trackSid: publication.trackSid,
        isSubscribed: publication.isSubscribed,
        hasTrack: !!publication.track,
        timestamp: new Date().toISOString()
      });

      // If track is already subscribed, handle it immediately
      if (publication.isSubscribed && publication.track) {
        console.log('[VideoFeed] Track already subscribed, handling now', { name });
        handleTrackSubscribed(publication.track);
      }
    };

    // ✅ Room-level event listeners (for pre-existing tracks)
    let roomCleanup = null;
    if (room && !isLocal) {
      console.log('[VideoFeed] ✅ Adding room-level event listeners', { name });
      
      const roomTrackSubscribed = (track, publication, remoteParticipant) => {
        console.log('[VideoFeed] 🎯 ROOM EVENT: trackSubscribed', {
          trackKind: track.kind,
          trackSid: track.sid,
          remoteParticipantSid: remoteParticipant?.sid,
          ourParticipantSid: participant.sid,
          timestamp: new Date().toISOString()
        });
        
        // Only handle if it's for THIS participant
        if (remoteParticipant?.sid === participant.sid) {
          console.log('[VideoFeed] ✅ Room event is for this participant!', { name });
          handleTrackSubscribed(track, publication, remoteParticipant);
        }
      };
      
      room.on('trackSubscribed', roomTrackSubscribed);
      
      roomCleanup = () => {
        console.log('[VideoFeed] 🔴 Removing room-level listeners', { name });
        room.off('trackSubscribed', roomTrackSubscribed);
      };
    }

    // Listen for track events
    participant.on('trackSubscribed', handleTrackSubscribed);
    participant.on('trackUnsubscribed', handleTrackUnsubscribed);
    participant.on('trackPublished', handleTrackPublished);
    participant.on('isSpeakingChanged', handleIsSpeakingChanged);

    console.log('[VideoFeed] ✅ Event listeners registered', { 
      name,
      participantLevel: true,
      roomLevel: !!room && !isLocal,
      timestamp: new Date().toISOString()
    });

    // 🔍 DEBUG: Log current state of tracks
    console.log('[VideoFeed] 📊 Current track state:', {
      name,
      audioTracksCount: participant.audioTrackPublications?.size || 0,
      videoTracksCount: participant.videoTrackPublications?.size || 0,
      audioTracks: participant.audioTrackPublications ? Array.from(participant.audioTrackPublications.values()).map(pub => ({
        sid: pub.trackSid,
        kind: pub.kind,
        isSubscribed: pub.isSubscribed,
        hasTrack: !!pub.track
      })) : [],
      videoTracks: participant.videoTrackPublications ? Array.from(participant.videoTrackPublications.values()).map(pub => ({
        sid: pub.trackSid,
        kind: pub.kind,
        isSubscribed: pub.isSubscribed,
        hasTrack: !!pub.track
      })) : []
    });

    // ✅ NEW: Immediate check for pre-existing subscribed tracks at mount time
    console.log('[VideoFeed] 🔍 Checking for pre-existing tracks IMMEDIATELY at mount', { name });
    console.log('[VideoFeed] 🔬 DETAILED: participant.audioTrackPublications type:', typeof participant.audioTrackPublications);
    console.log('[VideoFeed] 🔬 DETAILED: participant.audioTrackPublications is Map?', participant.audioTrackPublications instanceof Map);
    console.log('[VideoFeed] 🔬 DETAILED: participant.audioTrackPublications size:', participant.audioTrackPublications?.size);
    console.log('[VideoFeed] 🔬 DETAILED: participant.videoTrackPublications size:', participant.videoTrackPublications?.size);
    
    let foundPreExisting = false;
    
    if (participant.audioTrackPublications) {
      let audioIndex = 0;
      participant.audioTrackPublications.forEach((publication) => {
        console.log(`[VideoFeed] 🔬 AUDIO TRACK #${audioIndex++}:`, {
          name,
          trackSid: publication.trackSid,
          kind: publication.kind,
          isSubscribed: publication.isSubscribed,
          hasTrack: !!publication.track,
          track: publication.track,
          trackEnabled: publication.track?.enabled,
          trackMuted: publication.track?.muted
        });
        
        if (publication.isSubscribed && publication.track) {
          console.log('[VideoFeed] ✅ Found pre-existing AUDIO track at mount!', {
            name,
            trackSid: publication.trackSid
          });
          handleTrackSubscribed(publication.track, publication, participant);
          foundPreExisting = true;
        } else if (publication.isSubscribed && !publication.track) {
          console.warn('[VideoFeed] ⚠️ Track is subscribed but track object is NULL!', {
            name,
            trackSid: publication.trackSid
          });
        } else if (!publication.isSubscribed) {
          console.warn('[VideoFeed] ⚠️ Publication exists but NOT subscribed yet!', {
            name,
            trackSid: publication.trackSid
          });
        }
      });
    }

    if (participant.videoTrackPublications) {
      let videoIndex = 0;
      participant.videoTrackPublications.forEach((publication) => {
        console.log(`[VideoFeed] 🔬 VIDEO TRACK #${videoIndex++}:`, {
          name,
          trackSid: publication.trackSid,
          kind: publication.kind,
          isSubscribed: publication.isSubscribed,
          hasTrack: !!publication.track
        });
        
        if (publication.isSubscribed && publication.track) {
          console.log('[VideoFeed] ✅ Found pre-existing VIDEO track at mount!', {
            name,
            trackSid: publication.trackSid
          });
          handleTrackSubscribed(publication.track, publication, participant);
          foundPreExisting = true;
        }
      });
    }

    if (foundPreExisting) {
      console.log('[VideoFeed] ✅ Successfully attached pre-existing tracks', { name });
    } else {
      console.log('[VideoFeed] ⚠️ No pre-existing tracks found at mount, will rely on events', { name });
    }

    // ✅ FIX: Multiple retry checks for auto-subscribed tracks (as fallback)
    // LiveKit's autoSubscribe happens asynchronously and timing varies
    // Try checking at multiple intervals with detailed logging
    
    // Track which tracks we've already handled to prevent duplicates
    const handledTrackSids = new Set();
    
    const checkForAutoSubscribedTracks = (attemptNumber) => {
      console.log(`[VideoFeed] ⏰ Retry check #${attemptNumber}: Looking for auto-subscribed tracks`, { 
        name,
        timestamp: new Date().toISOString()
      });
      
      console.log(`[VideoFeed] 🔬 Retry #${attemptNumber} - Track state:`, {
        name,
        audioTracksSize: participant.audioTrackPublications?.size || 0,
        videoTracksSize: participant.videoTrackPublications?.size || 0,
        audioTracksDetailed: participant.audioTrackPublications ? Array.from(participant.audioTrackPublications.values()).map(pub => ({
          sid: pub.trackSid,
          isSubscribed: pub.isSubscribed,
          hasTrack: !!pub.track
        })) : [],
        videoTracksDetailed: participant.videoTrackPublications ? Array.from(participant.videoTrackPublications.values()).map(pub => ({
          sid: pub.trackSid,
          isSubscribed: pub.isSubscribed,
          hasTrack: !!pub.track
        })) : []
      });

      let foundTracks = false;

      // Check audio tracks
      if (participant.audioTrackPublications) {
        participant.audioTrackPublications.forEach((publication) => {
          if (publication.isSubscribed && publication.track && !handledTrackSids.has(publication.trackSid)) {
            console.log(`[VideoFeed] ✅ Retry #${attemptNumber}: Found auto-subscribed AUDIO track!`, {
              name,
              trackSid: publication.trackSid,
              attemptNumber
            });
            handledTrackSids.add(publication.trackSid);
            handleTrackSubscribed(publication.track);
            foundTracks = true;
          }
        });
      }

      // Check video tracks
      if (participant.videoTrackPublications) {
        participant.videoTrackPublications.forEach((publication) => {
          if (publication.isSubscribed && publication.track && !handledTrackSids.has(publication.trackSid)) {
            console.log(`[VideoFeed] ✅ Retry #${attemptNumber}: Found auto-subscribed VIDEO track!`, {
              name,
              trackSid: publication.trackSid,
              attemptNumber
            });
            handledTrackSids.add(publication.trackSid);
            handleTrackSubscribed(publication.track);
            foundTracks = true;
          }
        });
      }

      if (!foundTracks) {
        console.log(`[VideoFeed] ⏰ Retry check #${attemptNumber}: No new auto-subscribed tracks found`, { 
          name,
          timestamp: new Date().toISOString()
        });
      } else {
        console.log(`[VideoFeed] 🎉 Retry #${attemptNumber} SUCCESS: Tracks attached via retry mechanism!`, { name });
      }
    };

    // Extended retry intervals: 500ms, 1s, 2s, 4s, 8s
    const timer1 = setTimeout(() => checkForAutoSubscribedTracks(1), 500);
    const timer2 = setTimeout(() => checkForAutoSubscribedTracks(2), 1000);
    const timer3 = setTimeout(() => checkForAutoSubscribedTracks(3), 2000);
    const timer4 = setTimeout(() => checkForAutoSubscribedTracks(4), 4000);
    const timer5 = setTimeout(() => checkForAutoSubscribedTracks(5), 8000);

    return () => {
      clearTimeout(timer1);
      clearTimeout(timer2);
      clearTimeout(timer3);
      clearTimeout(timer4);
      clearTimeout(timer5);
      console.log('[VideoFeed] 🔴 UNMOUNT - Removing track listeners', {
        name,
        timestamp: new Date().toISOString()
      });
      participant.off('trackSubscribed', handleTrackSubscribed);
      participant.off('trackUnsubscribed', handleTrackUnsubscribed);
      participant.off('trackPublished', handleTrackPublished);
      participant.off('isSpeakingChanged', handleIsSpeakingChanged);
      
      // Clean up room-level listeners
      if (roomCleanup) {
        roomCleanup();
      }
    };
  }, [participant, room, name, isLocal]);

  // Attach local participant tracks - ONLY via events
  useEffect(() => {
    if (!participant || !isLocal) return;

    console.log('[VideoFeed] Setting up local track listeners', { name });

    const handleLocalTrackPublished = (publication) => {
      console.log('[VideoFeed] Local track published', {
        name,
        kind: publication.kind,
        hasTrack: !!publication.track
      });

      // ✅ FIX: Set state first to trigger video element rendering
      if (publication.kind === 'video' && publication.track) {
        setVideoTrack(publication.track);
        console.log('[VideoFeed] Local video track state set (will attach MediaStream in next effect)', { name });
      } else if (publication.kind === 'audio' && publication.track) {
        setAudioTrack(publication.track);
        console.log('[VideoFeed] Local audio track detected', { name });
      }
    };

    // Listen for track published events
    participant.on('localTrackPublished', handleLocalTrackPublished);

    // Check if tracks already exist (in case they were published before this component mounted)
    if (participant.videoTrackPublications) {
      participant.videoTrackPublications.forEach((publication) => {
        if (publication.track) {
          handleLocalTrackPublished(publication);
        }
      });
    }
    if (participant.audioTrackPublications) {
      participant.audioTrackPublications.forEach((publication) => {
        if (publication.track) {
          handleLocalTrackPublished(publication);
        }
      });
    }

    return () => {
      participant.off('localTrackPublished', handleLocalTrackPublished);
    };
  }, [participant, isLocal, name]);

  // ✅ NEW: Attach MediaStream to local video element AFTER it's rendered
  useEffect(() => {
    if (!isLocal || !videoTrack || !videoRef.current) return;

    const videoElement = videoRef.current;
    const mediaStreamTrack = videoTrack.mediaStreamTrack;

    if (mediaStreamTrack) {
      // ✅ FIX: Clear previous srcObject before attaching new one
      if (videoElement.srcObject) {
        console.log('[VideoFeed] Clearing previous MediaStream before re-attaching', { name });
        videoElement.srcObject = null;
      }

      const stream = new MediaStream([mediaStreamTrack]);
      videoElement.srcObject = stream;

      // Force video to play
      videoElement.play().catch(err => {
        console.error('[VideoFeed] Failed to play local video', err);
      });

      console.log('[VideoFeed] ✅ Local video MediaStream attached!', { 
        name,
        mediaStreamTrackId: mediaStreamTrack.id,
        hasMediaStreamTrack: !!mediaStreamTrack,
        streamActive: stream.active
      });
    } else {
      console.error('[VideoFeed] No MediaStreamTrack found on local video track', { name });
    }

    return () => {
      if (videoElement.srcObject) {
        videoElement.srcObject = null;
        console.log('[VideoFeed] Local video MediaStream detached', { name });
      }
    };
  }, [isLocal, videoTrack?.mediaStreamTrack?.id, name]); // ✅ FIX: Track mediaStreamTrack.id to ensure effect re-runs on camera re-enable

  // Attach video track to video element (for remote participants) using MediaStream
  useEffect(() => {
    if (videoTrack && videoRef.current && !isLocal) {
      const videoElement = videoRef.current;
      const mediaStreamTrack = videoTrack.mediaStreamTrack;

      if (mediaStreamTrack) {
        // ✅ FIX: Use MediaStream instead of attach/detach to preserve tracks during unmount
        const stream = new MediaStream([mediaStreamTrack]);
        videoElement.srcObject = stream;

        // Force video to play
        videoElement.play().catch(err => {
          console.error('[VideoFeed] Failed to play remote video', { name, error: err });
        });

        console.log('[VideoFeed] ✅ Remote video MediaStream attached!', { 
          name,
          mediaStreamTrackId: mediaStreamTrack.id,
          streamActive: stream.active
        });
      } else {
        console.error('[VideoFeed] No MediaStreamTrack found on remote video track', { name });
      }

      return () => {
        // ✅ FIX: Just clear srcObject, don't detach the track
        if (videoElement.srcObject) {
          videoElement.srcObject = null;
          console.log('[VideoFeed] Remote video MediaStream cleared', { name });
        }
      };
    }
  }, [videoTrack?.mediaStreamTrack?.id, name, isLocal]);

  // Attach audio track to audio element (but not for local user to avoid echo) using MediaStream
  useEffect(() => {
    if (audioTrack && audioRef.current && !isLocal) {
      const audioElement = audioRef.current;
      const mediaStreamTrack = audioTrack.mediaStreamTrack;

      if (mediaStreamTrack) {
        // ✅ FIX: Use MediaStream instead of attach/detach to preserve tracks during unmount
        const stream = new MediaStream([mediaStreamTrack]);
        audioElement.srcObject = stream;

        console.log('[VideoFeed] ✅ Remote audio MediaStream attached!', { 
          name,
          mediaStreamTrackId: mediaStreamTrack.id,
          streamActive: stream.active
        });
      
        // ✅ FIX: Attempt to play audio immediately (will fail due to autoplay policy until user interacts)
        audioElement.play().catch(error => {
          if (error.name === 'NotAllowedError') {
            console.warn('[VideoFeed] ⚠️ Audio autoplay blocked by browser. Will retry on user interaction.', { name });
            
            // ✅ NEW: Resume audio playback on ANY user interaction
            const resumeAudio = () => {
              console.log('[VideoFeed] 🔊 Attempting to resume audio after user interaction', { name });
              audioElement.play().then(() => {
                console.log('[VideoFeed] ✅ Audio resumed successfully!', { name });
                // Remove listeners after successful playback
                document.removeEventListener('click', resumeAudio);
                document.removeEventListener('keydown', resumeAudio);
                document.removeEventListener('touchstart', resumeAudio);
              }).catch(err => {
                console.error('[VideoFeed] Failed to resume audio', { name, error: err });
              });
            };
            
            // Listen for user interactions
            document.addEventListener('click', resumeAudio, { once: true });
            document.addEventListener('keydown', resumeAudio, { once: true });
            document.addEventListener('touchstart', resumeAudio, { once: true });
          } else {
            console.error('[VideoFeed] Audio playback error', { name, error });
          }
        });
      } else {
        console.error('[VideoFeed] No MediaStreamTrack found on remote audio track', { name });
      }

      return () => {
        // ✅ FIX: Just clear srcObject, don't detach the track
        if (audioElement.srcObject) {
          audioElement.srcObject = null;
          console.log('[VideoFeed] Remote audio MediaStream cleared', { name });
        }
      };
    }
  }, [audioTrack?.mediaStreamTrack?.id, isLocal, name]);

  // ✅ FIX: For local participants, check if camera is enabled (not just if track exists)
  // For remote participants, check if video track exists
  const hasVideo = isLocal ? localVideoEnabled : !!videoTrack;

  const hasAudio = isLocal ? localAudioEnabled : !!audioTrack;
  const initials = name?.split(' ').map(n => n[0]).join('').toUpperCase() || '?';

  return (
    <div
      className={`relative bg-gray-900 rounded-lg overflow-hidden ${
        featured ? 'aspect-video' : 'aspect-square'
      } ${isSpeaking ? 'ring-4 ring-green-500' : 'ring-2 ring-gray-700'}`}
    >
      {/* Video element */}
      {hasVideo ? (
        <video
          ref={videoRef}
          autoPlay
          playsInline
          muted={isLocal}
          className="w-full object-contain"
        />
      ) : (
        /* Placeholder when no video */
        <div className="w-full h-full flex items-center justify-center bg-gradient-to-br from-indigo-600 to-purple-600">
          <div className={`${
            featured ? 'text-6xl' : 'text-3xl'
          } font-bold text-white`}>
            {initials}
          </div>
        </div>
      )}

      {/* Audio element (hidden, for remote participants only) */}
      {!isLocal && <audio ref={audioRef} autoPlay />}

      {/* Name and status overlay */}
      <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-3">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span className="text-white font-medium text-sm truncate">
              {name || 'Unknown'}
              {isLocal && ' (You)'}
            </span>
          </div>

          <div className="flex items-center gap-2">
            {/* Microphone indicator */}
            {hasAudio ? (
              <FaMicrophone className={`text-sm ${
                isSpeaking ? 'text-green-400' : 'text-white'
              }`} />
            ) : (
              <FaMicrophoneSlash className="text-sm text-red-400" />
            )}

            {/* Camera indicator */}
            {hasVideo ? (
              <FaVideo className="text-sm text-white" />
            ) : (
              <FaVideoSlash className="text-sm text-red-400" />
            )}
          </div>
        </div>
      </div>

      {/* Connection status indicator */}
      {participant && (
        <div className="absolute top-2 right-2">
          <div className={`w-2 h-2 rounded-full ${
            participant.connectionQuality === 'excellent' ? 'bg-green-500' :
            participant.connectionQuality === 'good' ? 'bg-yellow-500' :
            participant.connectionQuality === 'poor' ? 'bg-orange-500' :
            'bg-red-500'
          }`} />
        </div>
      )}
    </div>
  );
}
