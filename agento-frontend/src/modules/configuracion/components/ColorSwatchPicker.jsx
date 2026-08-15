import { COLOR_PALETTE } from '../../../utils/avatarColor';

export default function ColorSwatchPicker({ value, onChange }) {
  return (
    <div className="flex flex-wrap gap-2">
      {COLOR_PALETTE.map((color) => (
        <button
          key={color}
          type="button"
          onClick={() => onChange?.(color)}
          className={`h-8 w-8 rounded-full transition-transform ${
            value === color
              ? 'ring-2 ring-agento-blue-dark ring-offset-2'
              : 'hover:scale-110'
          }`}
          style={{ backgroundColor: color }}
          aria-label={`Color ${color}`}
        />
      ))}
    </div>
  );
}
