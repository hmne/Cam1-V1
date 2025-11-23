/**
 * Camera Control Center - TypeScript Entry Point
 *
 * Modern TypeScript version of the camera control system.
 * This file will be compiled by Vite to assets/dist/camera-control.js
 *
 * @category  CameraControl
 * @package   Frontend
 * @author    Net Storm
 * @version   1.0.0
 */

import type { AppConfig, AppState, QualityPresets } from './types';

// =============================================================================
// CONFIGURATION
// =============================================================================

const CONFIG: AppConfig = {
  CAM: window.CAMERA_NAME || 'Camera',
  STATUS_UPDATE_INTERVAL: 2000,
  LIVE_UPDATE_INTERVAL: 1500,
  CAPTURE_CHECK_FAST: 25,
  CAPTURE_CHECK_SLOW: 200,
  CAPTURE_MAX_ATTEMPTS: 200,
  OFFLINE_THRESHOLD: 7,
  LIVE_ERROR_THRESHOLD: 7,
  CAPTURE_RESTORE_DELAY: 500,
  LIVE_START_DELAY: 800,
  MAX_IMAGE_OBJECTS: 5,
  CLEANUP_INTERVAL: 30000,
  ENABLE_PAGE_VISIBILITY: window.ENABLE_PAGE_VISIBILITY !== false,
};

const QUALITY_PRESETS: QualityPresets = {
  'very-low': [480, 360, 8],
  'low': [640, 480, 16],
  'medium': [800, 600, 24],
  'high': [1024, 768, 32],
};

// =============================================================================
// STATE
// =============================================================================

const state: AppState = {
  statusInterval: null,
  webLiveInterval: null,
  sessionHeartbeatInterval: null,
  cleanupInterval: null,
  isLiveActive: false,
  lastOnlineTime: Date.now(),
  wasLiveBeforeOffline: false,
  captureLock: false,
  liveErrorCount: 0,
  currentQuality: null,
  statusRetryCount: 0,
  firstLoad: true,
  sessionId: null,
  imageObjects: [],
  isPageVisible: true,
  lastCacheBuster: 0,
  previousOnlineStatus: null,
  notificationsEnabled: false,
};

// =============================================================================
// UTILITIES
// =============================================================================

/**
 * Generate strong cache buster
 */
function generateCacheBuster(): string {
  const now = Date.now();

  if (now === state.lastCacheBuster) {
    state.lastCacheBuster = now + 1;
  } else {
    state.lastCacheBuster = now;
  }

  return `${state.lastCacheBuster}_${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * POST data to server
 */
async function postData(url: string, data: Record<string, string>): Promise<string> {
  const formData = new URLSearchParams(data);

  const response = await fetch(url, {
    method: 'POST',
    body: formData,
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });

  return response.text();
}

/**
 * Cleanup old Image objects
 */
function cleanupImageObjects(): void {
  if (state.imageObjects.length > CONFIG.MAX_IMAGE_OBJECTS) {
    const toRemove = state.imageObjects.length - CONFIG.MAX_IMAGE_OBJECTS;
    const removed = state.imageObjects.splice(0, toRemove);

    removed.forEach(img => {
      if (img) {
        img.onload = null;
        img.onerror = null;
        img.src = '';
      }
    });

    console.log(`[${CONFIG.CAM}] Cleaned up ${toRemove} old Image objects`);
  }
}

/**
 * Create tracked Image object
 */
function createTrackedImage(): HTMLImageElement {
  const img = new Image();
  state.imageObjects.push(img);
  cleanupImageObjects();
  return img;
}

// =============================================================================
// NOTIFICATIONS
// =============================================================================

/**
 * Request browser notification permission
 */
function requestNotificationPermission(): void {
  if (!('Notification' in window)) {
    return;
  }

  if (Notification.permission === 'granted') {
    state.notificationsEnabled = true;
  } else if (Notification.permission !== 'denied') {
    Notification.requestPermission().then(permission => {
      state.notificationsEnabled = permission === 'granted';
    });
  }
}

// =============================================================================
// STATUS MONITORING
// =============================================================================

/**
 * Load camera status
 */
async function loadCameraStatus(): Promise<void> {
  try {
    const response = await fetch(`mode.php?t=${generateCacheBuster()}`, {
      cache: 'no-store',
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const html = await response.text();
    const container = document.getElementById('id1');

    if (container) {
      container.innerHTML = html;

      // Execute inline scripts
      const scripts = container.getElementsByTagName('script');
      for (const script of Array.from(scripts)) {
        const newScript = document.createElement('script');
        newScript.text = script.text;
        document.head.appendChild(newScript).parentNode?.removeChild(newScript);
      }
    }

    state.statusRetryCount = 0;
    state.firstLoad = false;
  } catch (error) {
    console.error(`[${CONFIG.CAM}] Status load error:`, error);
    window.cameraOnlineStatus = false;
    window.secondsSinceUpdate = 999;
  }
}

/**
 * Start status monitoring
 */
function startStatusMonitoring(): void {
  if (state.statusInterval) {
    clearInterval(state.statusInterval);
  }

  loadCameraStatus();
  state.statusInterval = window.setInterval(loadCameraStatus, CONFIG.STATUS_UPDATE_INTERVAL);
}

// =============================================================================
// INITIALIZATION
// =============================================================================

/**
 * Initialize application
 */
function initializeApp(): void {
  requestNotificationPermission();
  startStatusMonitoring();

  // Memory cleanup
  state.cleanupInterval = window.setInterval(cleanupImageObjects, CONFIG.CLEANUP_INTERVAL);

  console.log(`[${CONFIG.CAM}] TypeScript Camera Control initialized`);
}

// Start when DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeApp);
} else {
  initializeApp();
}

// Export for testing
export { CONFIG, QUALITY_PRESETS, state, generateCacheBuster, cleanupImageObjects };
