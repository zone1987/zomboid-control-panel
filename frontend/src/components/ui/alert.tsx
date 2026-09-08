import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "cn"

const alertVariants = cva(
  "relative grid w-full grid-cols-[0_1fr] items-start gap-y-0.5 rounded-lg border px-4 py-3 text-sm has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] has-[>svg]:gap-x-3 [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        default: "bg-card text-card-foreground",
        /**
         * The three states an alert can report besides a fault.
         *
         * Nine sites used to fake these with a coloured icon inside a
         * neutral box, which reads as unfinished rather than quiet.
         * The shades are the computed ones from `badge.tsx`: on white,
         * emerald-600 is 3.77:1 and fails AA for small text, so the
         * text sits a shade darker over a faint tint.
         */
        success:
          "border-emerald-600/25 bg-emerald-50 text-emerald-800 *:data-[slot=alert-description]:text-emerald-700 dark:border-emerald-400/25 dark:bg-emerald-950/40 dark:text-emerald-200 dark:*:data-[slot=alert-description]:text-emerald-300",
        warning:
          "border-amber-600/25 bg-amber-50 text-amber-800 *:data-[slot=alert-description]:text-amber-700 dark:border-amber-400/25 dark:bg-amber-950/40 dark:text-amber-200 dark:*:data-[slot=alert-description]:text-amber-300",
        info:
          "border-violet-600/25 bg-violet-50 text-violet-800 *:data-[slot=alert-description]:text-violet-700 dark:border-violet-400/25 dark:bg-violet-950/40 dark:text-violet-200 dark:*:data-[slot=alert-description]:text-violet-300",
        destructive:
          "bg-card text-destructive *:data-[slot=alert-description]:text-destructive/90 [&>svg]:text-current",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Alert({
  className,
  variant,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof alertVariants>) {
  return (
    <div
      data-slot="alert"
      role="alert"
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  )
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn(
        "col-start-2 line-clamp-1 min-h-4 font-medium tracking-tight",
        className
      )}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn(
        "col-start-2 grid justify-items-start gap-1 text-sm text-muted-foreground [&_p]:leading-relaxed",
        className
      )}
      {...props}
    />
  )
}

export { Alert, AlertTitle, AlertDescription }
