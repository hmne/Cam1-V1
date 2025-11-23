/**
 * Camera Control Center - Type Definitions
 *
 * TypeScript type definitions for the camera control system.
 *
 * @category  Types
 * @package   CameraControl
 * @author    Net Storm
 * @version   1.0.0
 */

/**
 * Application configuration
 */
export interface AppConfig {
  CAM: string;
  STATUS_UPDATE_INTERVAL: number;
  LIVE_UPDATE_INTERVAL: number;
  CAPTURE_CHECK_FAST: number;
  CAPTURE_CHECK_SLOW: number;
  CAPTURE_MAX_ATTEMPTS: number;
  OFFLINE_THRESHOLD: number;
  LIVE_ERROR_THRESHOLD: number;
  CAPTURE_RESTORE_DELAY: number;
  LIVE_START_DELAY: number;
  MAX_IMAGE_OBJECTS: number;
  CLEANUP_INTERVAL: number;
  ENABLE_PAGE_VISIBILITY: boolean;
}

/**
 * Application state
 */
export interface AppState {
  statusInterval: number | null;
  webLiveInterval: number | null;
  sessionHeartbeatInterval: number | null;
  cleanupInterval: number | null;
  isLiveActive: boolean;
  lastOnlineTime: number;
  wasLiveBeforeOffline: boolean;
  captureLock: boolean;
  liveErrorCount: number;
  currentQuality: string | null;
  statusRetryCount: number;
  firstLoad: boolean;
  sessionId: string | null;
  imageObjects: HTMLImageElement[];
  isPageVisible: boolean;
  lastCacheBuster: number;
  previousOnlineStatus: boolean | null;
  notificationsEnabled: boolean;
}

/**
 * Quality preset values [width, height, quality]
 */
export type QualityPreset = [number, number, number];

/**
 * Quality presets map
 */
export interface QualityPresets {
  'very-low': QualityPreset;
  'low': QualityPreset;
  'medium': QualityPreset;
  'high': QualityPreset;
}

/**
 * Camera status data
 */
export interface CameraStatus {
  isOnline: boolean;
  secondsSinceUpdate: number;
  temperature?: string;
  memory?: string;
  signal?: string;
  transmitted?: string;
}

/**
 * Live stream quality settings
 */
export interface LiveQualitySettings {
  width: number;
  height: number;
  quality: number;
}

/**
 * Capture form data
 */
export interface CaptureFormData {
  res: string;
  comp: string;
  iso: string;
  sat: string;
  rot: string;
  fx: string;
  enf: string;
  b1: string;
  submit: string;
}

/**
 * AJAX response types
 */
export interface AjaxResponse {
  success?: boolean;
  error?: string;
  message?: string;
  data?: unknown;
}

/**
 * File write request
 */
export interface FileWriteRequest {
  action: 'write';
  file: string;
  data: string;
}

/**
 * Notification options
 */
export interface NotificationOptions {
  body: string;
  icon?: string;
  tag?: string;
  renotify?: boolean;
}

/**
 * DOM element cache
 */
export interface DOMCache {
  statusContainer: HTMLElement | null;
  liveSelect: HTMLSelectElement | null;
  liveContainer: HTMLElement | null;
  liveImage: HTMLImageElement | null;
  captureButton: HTMLButtonElement | null;
  qualitySelect: HTMLSelectElement | null;
  form: HTMLFormElement | null;
}

/**
 * Global window extensions
 */
declare global {
  interface Window {
    CAMERA_NAME?: string;
    ADMIN_TOKEN?: string;
    ENABLE_PAGE_VISIBILITY?: boolean;
    JS_MODE?: string;
    cameraOnlineStatus?: boolean;
    secondsSinceUpdate?: number;
    captureImage?: () => void;
    toggleWebLive?: () => void;
    updateLiveQuality?: (forceUpdate?: boolean) => void;
    saveImageToDevice?: () => void;
  }
}

export {};
