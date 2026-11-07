import markNavy from '../assets/logo-mark.svg'
import markWhite from '../assets/logo-mark-white.svg'

// The official Flowcast mark. The SVG files are generated from brand/logo-mark.svg
// (run `npm run build` in brand/); never redraw or recolor the mark here.
//   tone="navy"  on white or light backgrounds
//   tone="white" on the navy sidebar, top bar or the dark TV board
interface LogoMarkProps {
  tone?: 'navy' | 'white'
  className?: string
  // Give a label only when the mark stands alone; next to the word "Flowcast"
  // it is decorative and screen readers should skip it.
  label?: string
}

export function LogoMark({ tone = 'navy', className = 'h-8 w-8', label }: LogoMarkProps) {
  return (
    <img
      src={tone === 'white' ? markWhite : markNavy}
      alt={label ?? ''}
      aria-hidden={label ? undefined : true}
      className={className}
      draggable={false}
    />
  )
}
