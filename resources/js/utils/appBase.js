export const getAppBase = () => {
  if (typeof window === 'undefined') return '';
  return window.__API_APP_BASE__ || '';
};

export const appPath = (path) => {
  const base = getAppBase();
  if (!base) return path;
  const normalized = path.startsWith('/') ? path : `/${path}`;
  return `${base}${normalized}`;
};
