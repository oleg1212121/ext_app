import {VIEW, buildDiagram} from '../../data/phonemes/art.js';

function StateSvg({state, className = 'h-full w-full'}) {
    return (
        <svg viewBox={`0 0 ${VIEW.w} ${VIEW.h}`} className={className} aria-hidden="true" focusable="false">
            {buildDiagram(state).map((shape, i) => (
                shape.smallCircle ? (
                    <circle
                        key={i}
                        cx={shape.smallCircle.cx}
                        cy={shape.smallCircle.cy}
                        r={shape.smallCircle.r}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="1.6"
                    />
                ) : (
                    <path
                        key={i}
                        d={shape.d}
                        fill={shape.fill ? 'currentColor' : 'none'}
                        fillOpacity={shape.fill ?? undefined}
                        stroke={shape.width ? 'currentColor' : 'none'}
                        strokeWidth={shape.width ?? undefined}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        opacity={shape.opacity ?? undefined}
                    />
                )
            ))}
        </svg>
    );
}

/**
 * One or two mid-sagittal articulation states. Two states (diphthongs,
 * affricates) render side by side as a start → end sequence; the states are
 * plain data, so the animation phase can interpolate between them instead of
 * replacing this component.
 */
export default function ArticulationDiagram({art, phaseStartLabel, phaseEndLabel, className = ''}) {
    if (art.length === 2) {
        return (
            <div className={`flex items-stretch gap-2 ${className}`}>
                <div className="flex min-w-0 flex-1 flex-col">
                    <StateSvg state={art[0]}/>
                    {phaseStartLabel && <span className="mt-1 text-center text-[10px] uppercase tracking-[0.14em] opacity-50">{phaseStartLabel}</span>}
                </div>
                <span aria-hidden="true" className="self-center shrink-0 text-lg opacity-40">→</span>
                <div className="flex min-w-0 flex-1 flex-col">
                    <StateSvg state={art[1]}/>
                    {phaseEndLabel && <span className="mt-1 text-center text-[10px] uppercase tracking-[0.14em] opacity-50">{phaseEndLabel}</span>}
                </div>
            </div>
        );
    }
    return (
        <div className={className}>
            <StateSvg state={art[0]}/>
        </div>
    );
}
