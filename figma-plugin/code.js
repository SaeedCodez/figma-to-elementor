// Figma → Elementor Token Export
// Main thread. Reads local variables + text styles, builds the copy-paste
// JSON payload, and posts it to the UI. No network access.

figma.showUI(__html__, { width: 480, height: 620, themeColors: true });

// --- color helpers ---------------------------------------------------------

function channelToHex(channel) {
  var value = Math.round(channel * 255);
  if (value < 0) value = 0;
  if (value > 255) value = 255;
  var hex = value.toString(16);
  return hex.length === 1 ? '0' + hex : hex;
}

function rgbToHex(rgb) {
  return '#' + channelToHex(rgb.r) + channelToHex(rgb.g) + channelToHex(rgb.b);
}

// --- main extraction -------------------------------------------------------

async function extract() {
  var variables = await figma.variables.getLocalVariablesAsync();

  // id -> variable map, so alias chains can be resolved.
  var byId = {};
  variables.forEach(function (variable) {
    byId[variable.id] = variable;
  });

  // The value of a variable in its first mode. Each collection in this file
  // has a single mode, so the first key is the one we want.
  function firstModeValue(variable) {
    if (!variable || !variable.valuesByMode) return undefined;
    var modeIds = Object.keys(variable.valuesByMode);
    if (modeIds.length === 0) return undefined;
    return variable.valuesByMode[modeIds[0]];
  }

  // Recursively resolve VARIABLE_ALIAS chains until a concrete value is found.
  function resolveValue(value, depth) {
    if (depth > 50) return undefined; // guard against alias cycles
    if (value && typeof value === 'object' && value.type === 'VARIABLE_ALIAS') {
      var target = byId[value.id];
      if (!target) return undefined;
      return resolveValue(firstModeValue(target), depth + 1);
    }
    return value;
  }

  var customColors = [];
  var colorByName = {}; // variable name -> hex, used for systemColors lookups
  var bodyFamily = '';
  var titleFamily = '';
  var weights = {};
  var sizes = {};

  variables.forEach(function (variable) {
    var raw = firstModeValue(variable);

    if (variable.resolvedType === 'COLOR') {
      var color = resolveValue(raw, 0);
      if (color && typeof color === 'object' && typeof color.r === 'number') {
        var hex = rgbToHex(color);
        customColors.push({ name: variable.name, hex: hex });
        colorByName[variable.name] = hex;
      }
    } else if (variable.resolvedType === 'STRING') {
      var str = resolveValue(raw, 0);
      if (typeof str === 'string') {
        if (variable.name === 'font/family') bodyFamily = str;
        else if (variable.name === 'font/title') titleFamily = str;
      }
    } else if (variable.resolvedType === 'FLOAT') {
      var num = resolveValue(raw, 0);
      if (typeof num === 'number') {
        if (variable.name.indexOf('font/weight/') === 0) {
          weights[variable.name.slice('font/weight/'.length)] = num;
        } else if (variable.name.indexOf('font/size/') === 0) {
          sizes[variable.name.slice('font/size/'.length)] = num;
        }
      }
    }
  });

  // Fallback: if no font/family variable, use the first local text style family.
  if (!bodyFamily) {
    try {
      var textStyles = await figma.getLocalTextStylesAsync();
      if (textStyles.length > 0 && textStyles[0].fontName && textStyles[0].fontName.family) {
        bodyFamily = textStyles[0].fontName.family;
      }
    } catch (e) {
      // ignore — bodyFamily simply stays empty
    }
  }

  // Map Elementor's four fixed system-color slots, with fallbacks.
  function pick() {
    for (var i = 0; i < arguments.length; i++) {
      if (colorByName[arguments[i]]) return colorByName[arguments[i]];
    }
    return '';
  }

  var systemColors = {
    primary: pick('seed/primary', 'primary/500'),
    secondary: pick('seed/secondary', 'secondary/500'),
    accent: pick('seed/accent', 'accent/500'),
    text: pick('semantic/black-ink', 'ink/900')
  };

  var typography = { titleFamily: titleFamily, bodyFamily: bodyFamily };
  if (Object.keys(weights).length > 0) typography.weights = weights;
  if (Object.keys(sizes).length > 0) typography.sizes = sizes;

  return {
    version: 1,
    source: 'figma-niikak',
    generatedAt: new Date().toISOString(),
    systemColors: systemColors,
    customColors: customColors,
    typography: typography
  };
}

extract()
  .then(function (payload) {
    figma.ui.postMessage({ type: 'payload', payload: payload });
  })
  .catch(function (err) {
    figma.ui.postMessage({
      type: 'error',
      message: (err && err.message) ? err.message : String(err)
    });
  });

// Allow the UI to request a close.
figma.ui.onmessage = function (msg) {
  if (msg && msg.type === 'close') figma.closePlugin();
};
