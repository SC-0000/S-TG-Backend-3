import React, { createContext, useContext, useState, useEffect, useCallback, useRef } from 'react';
import { apiClient } from '@/api';

const LessonPlayerContext = createContext(null);

export const useLessonPlayer = () => {
  const context = useContext(LessonPlayerContext);
  if (!context) {
    throw new Error('useLessonPlayer must be used within LessonPlayerProvider');
  }
  return context;
};

export const LessonPlayerProvider = ({ children, lesson, initialProgress, slides = [], initialNavigationLocked = false, childId = null }) => {
  // Core state
  const [progress, setProgress] = useState(initialProgress);
  const [currentSlide, setCurrentSlide] = useState(null);
  const [currentSlideIndex, setCurrentSlideIndex] = useState(0);
  const [allSlides, setAllSlides] = useState(slides);
  const [timeSpent, setTimeSpent] = useState(initialProgress?.time_spent_seconds || 0);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState(null);
  const [navigationLocked, setNavigationLocked] = useState(initialNavigationLocked);

  // Help panel state
  const [helpPanelOpen, setHelpPanelOpen] = useState(false);
  const [ttsEnabled, setTtsEnabled] = useState(false);
  const [aiChatOpen, setAiChatOpen] = useState(false);

  // Refs for tracking
  const startTimeRef = useRef(Date.now());
  const lastSyncRef = useRef(Date.now());
  const progressTimerRef = useRef(null);

  // Load initial slide (slides already have full content from controller)
  useEffect(() => {
    console.log('LessonPlayerContext initialized');
    console.log('Received slides:', allSlides);
    console.log('Progress:', progress);
    
    if (allSlides.length > 0) {
      const startIndex = progress?.last_slide_id 
        ? allSlides.findIndex(s => s.id === progress.last_slide_id)
        : 0;
      const index = startIndex >= 0 ? startIndex : 0;
      
      console.log('Loading slide at index:', index);
      console.log('Slide data:', allSlides[index]);
      console.log('Slide blocks:', allSlides[index]?.blocks);
      
      setCurrentSlideIndex(index);
      setCurrentSlide(allSlides[index]); // Slide already has blocks from controller
    } else {
      console.warn('No slides available');
    }
  }, []);

  // ✅ Update currentSlide when currentSlideIndex changes (for teacher-controlled slide changes)
  useEffect(() => {
    if (allSlides.length > 0 && currentSlideIndex >= 0 && currentSlideIndex < allSlides.length) {
      const slide = allSlides[currentSlideIndex];
      console.log('[LessonPlayerContext] ✅ Slide index changed, updating currentSlide:', {
        index: currentSlideIndex,
        slideId: slide?.id,
        slideTitle: slide?.title,
        blocks: slide?.blocks?.length
      });
      setCurrentSlide(slide);
    }
  }, [currentSlideIndex, allSlides]);

  // Sync progress to backend (defined early so useEffect can reference it)
  const syncProgress = useCallback(async () => {
    if (!lesson || !currentSlide) return;

    try {
      await apiClient.post(`/lesson-player/${lesson.id}/progress`, {
        last_slide_id: currentSlide.id,
        time_spent_seconds: timeSpent,
        slides_viewed: progress?.slides_viewed || [],
        ...(childId ? { child_id: childId } : {}),
      }, { useToken: true });
      lastSyncRef.current = Date.now();
      console.log('[LessonPlayerContext] Progress synced successfully');
    } catch (err) {
      console.error('[LessonPlayerContext] Failed to sync progress:', err);
    }
  }, [lesson, currentSlide, timeSpent, progress, childId]);

  // Time tracking - update every second
  useEffect(() => {
    const interval = setInterval(() => {
      setTimeSpent(prev => prev + 1);
    }, 1000);

    return () => clearInterval(interval);
  }, []);

  // Auto-sync progress every 10 seconds
  useEffect(() => {
    const syncInterval = setInterval(() => {
      syncProgress();
    }, 10000); // 10 seconds

    return () => clearInterval(syncInterval);
  }, [syncProgress]);

  // Sync on unmount
  useEffect(() => {
    return () => {
      syncProgress();
    };
  }, [syncProgress]);

  // Update progress state
  const updateProgress = useCallback((updates) => {
    setProgress(prev => ({
      ...prev,
      ...updates,
    }));
  }, []);

  // Record slide view
  const recordSlideView = useCallback(async (slideId) => {
    if (!lesson) return;

    try {
      await apiClient.post(`/lesson-player/${lesson.id}/slides/${slideId}/view`, {
        ...(childId ? { child_id: childId } : {}),
      }, { useToken: true });
      
      // Update local progress
      updateProgress({
        slides_viewed: [...new Set([...(progress?.slides_viewed || []), slideId])],
        last_slide_id: slideId,
      });
    } catch (err) {
      console.error('Failed to record slide view:', err);
    }
  }, [lesson, progress, updateProgress, childId]);

  // Navigate to slide by index
  const goToSlide = useCallback((index) => {
    // Block navigation if locked by teacher
    if (navigationLocked) {
      console.log('[LessonPlayerContext] Navigation blocked - teacher has locked navigation');
      return;
    }

    if (index < 0 || index >= allSlides.length) return;

    const slide = allSlides[index];
    console.log('Navigating to slide:', index, slide);
    
    setCurrentSlideIndex(index);
    setCurrentSlide(slide); // Slide already has blocks
    recordSlideView(slide.id);
  }, [allSlides, recordSlideView, navigationLocked]);

  // Navigate to next slide
  const nextSlide = useCallback(() => {
    // Block navigation if locked by teacher
    if (navigationLocked) {
      console.log('[LessonPlayerContext] Navigation blocked - teacher has locked navigation');
      return;
    }

    if (currentSlideIndex < allSlides.length - 1) {
      goToSlide(currentSlideIndex + 1);
    }
  }, [currentSlideIndex, allSlides.length, goToSlide, navigationLocked]);

  // Navigate to previous slide
  const prevSlide = useCallback(() => {
    // Block navigation if locked by teacher
    if (navigationLocked) {
      console.log('[LessonPlayerContext] Navigation blocked - teacher has locked navigation');
      return;
    }

    if (currentSlideIndex > 0) {
      goToSlide(currentSlideIndex - 1);
    }
  }, [currentSlideIndex, goToSlide, navigationLocked]);

  // Check if can navigate
  const canGoNext = currentSlideIndex < allSlides.length - 1;
  const canGoPrev = currentSlideIndex > 0;
  const isFirstSlide = currentSlideIndex === 0;
  const isLastSlide = currentSlideIndex === allSlides.length - 1;

  // Complete lesson
  const completeLesson = useCallback(async () => {
    if (!lesson) return;

    setIsLoading(true);
    try {
      const response = await apiClient.post(`/lesson-player/${lesson.id}/complete`, {
        ...(childId ? { child_id: childId } : {}),
      }, { useToken: true });
      updateProgress({
        status: 'completed',
        completion_percentage: 100,
        completed_at: new Date().toISOString(),
      });
      return response?.data;
    } catch (err) {
      setError('Failed to complete lesson');
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, [lesson, updateProgress, childId]);

  // Submit question answer
  const submitAnswer = useCallback(async (slideId, questionId, answer, blockId = 'default', timeSpentSeconds = 0, hintsUsed = []) => {
    if (!lesson) return;

    setIsLoading(true);
    try {
      const response = await apiClient.post(
        `/lesson-player/${lesson.id}/slides/${slideId}/questions/submit`,
        {
          block_id: blockId,
          question_id: questionId,
          answer_data: answer,
          time_spent_seconds: timeSpentSeconds,
          hints_used: hintsUsed,
          ...(childId ? { child_id: childId } : {}),
        }
      );

      // Update progress with question results
      const isCorrect = response?.data?.response?.is_correct;
      updateProgress({
        questions_attempted: (progress?.questions_attempted || 0) + 1,
        questions_correct: (progress?.questions_correct || 0) + (isCorrect ? 1 : 0),
      });

      return response?.data;
    } catch (err) {
      setError('Failed to submit answer');
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, [lesson, progress, updateProgress, childId]);

  // Upload file
  const uploadFile = useCallback(async (slideId, file, uploadBlockId) => {
    if (!lesson) return;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('upload_block_id', uploadBlockId);
    if (childId) {
      formData.append('child_id', childId);
    }

    setIsLoading(true);
    try {
      const response = await apiClient.post(
        `/lesson-player/${lesson.id}/slides/${slideId}/upload`,
        formData,
        { useToken: true }
      );
      return response?.data;
    } catch (err) {
      setError('Failed to upload file');
      throw err;
    } finally {
      setIsLoading(false);
    }
  }, [lesson, childId]);

  // Calculate completion percentage
  const calculateCompletion = useCallback(() => {
    if (!allSlides.length) return 0;
    const viewedCount = progress?.slides_viewed?.length || 0;
    return Math.round((viewedCount / allSlides.length) * 100);
  }, [allSlides, progress]);

  // Format time display
  const formatTime = useCallback((seconds) => {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  }, []);

  const value = {
    // Core state
    lesson,
    progress,
    currentSlide,
    currentSlideIndex,
    allSlides,
    timeSpent,
    isLoading,
    error,
    navigationLocked,
    setNavigationLocked, // ✅ Allow updating navigation lock state

    // Navigation
    goToSlide,
    nextSlide,
    prevSlide,
    setCurrentSlideIndex, // ✅ Still expose for teacher-controlled slide changes
    canGoNext,
    canGoPrev,
    isFirstSlide,
    isLastSlide,

    // Progress
    updateProgress,
    syncProgress,
    recordSlideView,
    completeLesson,
    calculateCompletion,

    // Actions
    submitAnswer,
    uploadFile,

    // Help panel
    helpPanelOpen,
    setHelpPanelOpen,
    ttsEnabled,
    setTtsEnabled,
    aiChatOpen,
    setAiChatOpen,

    // Utilities
    formatTime,
  };

  return (
    <LessonPlayerContext.Provider value={value}>
      {children}
    </LessonPlayerContext.Provider>
  );
};
