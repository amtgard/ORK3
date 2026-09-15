.wv-sig-widget { border: 1px solid #ccc; border-radius: 6px; padding: 10px; background: #fff; }
.wv-sig-tabs { display: flex; gap: 4px; margin-bottom: 8px; }
.wv-sig-tab { padding: 6px 12px; background: #eee; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; }
.wv-sig-tab-active { background: #fff; border-bottom-color: #fff; font-weight: bold; }
.wv-sig-canvas { display: block; width: 100%; max-width: 600px; height: 180px; border: 1px dashed #888; border-radius: 4px; touch-action: none; background: #fafafa; }
.wv-sig-actions { margin-top: 6px; display: flex; gap: 6px; }
.wv-sig-actions button { padding: 4px 10px; }
.wv-sig-typed { font-family: 'Homemade Apple', 'Caveat', 'Brush Script MT', cursive; font-size: 28px; width: 100%; padding: 8px 10px; border: 1px dashed #888; border-radius: 4px; }
.wv-sig-typed-preview { font-family: 'Homemade Apple', 'Caveat', 'Brush Script MT', cursive; font-size: 32px; min-height: 40px; margin-top: 6px; color: #333; }
.wv-sig-typed-label { display: block; font-size: 12px; color: #666; margin-bottom: 4px; }

/* --- Dark mode --- */
html[data-theme="dark"] .wv-sig-widget { background: #2d3748; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .wv-sig-tab { background: #1f2937; border-color: #4a5568; color: #a0aec0; }
html[data-theme="dark"] .wv-sig-tab-active { background: #2d3748; color: #e2e8f0; border-bottom-color: #2d3748; }
html[data-theme="dark"] .wv-sig-canvas { background: #fafafa; border-color: #718096; }
html[data-theme="dark"] .wv-sig-typed { background: #fafafa; border-color: #718096; color: #111; }
html[data-theme="dark"] .wv-sig-typed-preview { background: #fafafa; color: #111; padding: 4px 8px; border-radius: 4px; }
html[data-theme="dark"] .wv-sig-typed-label { color: #a0aec0; }
