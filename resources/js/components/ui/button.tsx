import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "btn-anim inline-flex cursor-pointer select-none items-center justify-center gap-2 whitespace-nowrap rounded-[11px] text-sm font-semibold transition-[color,box-shadow,background,transform] duration-150 hover:-translate-y-px active:translate-y-0 active:scale-[.98] disabled:pointer-events-none disabled:opacity-50 disabled:shadow-none [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
  {
    variants: {
      variant: {
        default:
          "bg-[linear-gradient(180deg,color-mix(in_oklab,var(--primary)_86%,white),var(--primary))] text-primary-foreground shadow-[0_8px_20px_-10px_var(--primary),inset_0_1px_0_rgb(255_255_255/.3)] hover:shadow-[0_14px_28px_-12px_var(--primary),inset_0_1px_0_rgb(255_255_255/.3)]",
        destructive:
          "bg-destructive text-white shadow-[0_6px_16px_-8px_var(--destructive)] hover:bg-destructive/90 hover:shadow-[0_12px_24px_-10px_var(--destructive)] focus-visible:ring-destructive/20 dark:focus-visible:ring-destructive/40",
        outline:
          "border border-[color-mix(in_oklab,var(--foreground)_17%,transparent)] bg-[color-mix(in_oklab,var(--card)_88%,transparent)] backdrop-blur-md shadow-[0_1px_0_rgb(255_255_255/.5)_inset,0_2px_4px_-1px_rgb(16_26_51/.12),0_6px_16px_-10px_rgb(16_26_51/.35)] hover:border-brand/55 hover:bg-card hover:text-foreground hover:shadow-[0_2px_4px_-1px_rgb(16_26_51/.14),0_12px_24px_-12px_rgb(16_26_51/.45)] dark:shadow-[0_2px_4px_-1px_rgb(0_0_0/.4),0_6px_16px_-10px_rgb(0_0_0/.6)]",
        secondary:
          "border border-[color-mix(in_oklab,var(--foreground)_10%,transparent)] bg-secondary text-secondary-foreground shadow-[0_2px_6px_-3px_rgb(16_26_51/.25)] hover:bg-secondary/80 hover:shadow-[0_8px_18px_-10px_rgb(16_26_51/.4)]",
        ghost: "bg-[color-mix(in_oklab,var(--foreground)_5%,transparent)] hover:bg-accent hover:text-accent-foreground hover:shadow-[0_6px_14px_-10px_rgb(16_26_51/.45)]",
        link: "text-primary underline decoration-primary/30 underline-offset-4 hover:decoration-primary hover:translate-y-0",
      },
      size: {
        default: "h-10 px-4 py-2 has-[>svg]:px-3.5",
        sm: "h-8 rounded-[9px] px-3 has-[>svg]:px-2.5",
        lg: "h-11 rounded-xl px-6 has-[>svg]:px-5",
        icon: "size-9",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
  }) {
  const Comp = asChild ? Slot : "button"

  return (
    <Comp
      data-slot="button"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

export { Button, buttonVariants }
