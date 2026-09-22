// Minimal example for a frontend. No runtime dependency is required.
export function applyPresetValues(preset, values) {
  const definition = structuredClone(preset.billingDefinitionTemplate);

  for (const input of preset.inputs) {
    const hasValue = Object.prototype.hasOwnProperty.call(values, input.id);
    if (!hasValue) {
      if (input.required && readPointer(definition, input.targets[0]) == null) {
        throw new Error(`Missing required input: ${input.id}`);
      }
      continue;
    }
    for (const target of input.targets) {
      writePointer(definition, target, values[input.id]);
    }
  }

  return definition;
}

function parts(pointer) {
  return pointer.split('/').slice(1).map(part => part.replace(/~1/g, '/').replace(/~0/g, '~'));
}

function readPointer(root, pointer) {
  let current = root;
  for (const part of parts(pointer)) {
    current = current?.[part];
  }
  return current;
}

function writePointer(root, pointer, value) {
  const path = parts(pointer);
  let current = root;
  for (const part of path.slice(0, -1)) {
    current = current[part];
  }
  current[path[path.length - 1]] = value;
}
