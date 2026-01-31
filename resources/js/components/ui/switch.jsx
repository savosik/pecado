import { Switch as ChakraSwitch } from "@chakra-ui/react"
import * as React from "react"

/**
 * @typedef {object} SwitchProps
 * @property {React.InputHTMLAttributes<HTMLInputElement>} [inputProps]
 * @property {React.RefObject<HTMLLabelElement | null>} [rootRef]
 * @property {{ on: React.ReactNode; off: React.ReactNode }} [trackLabel]
 * @property {{ on: React.ReactNode; off: React.ReactNode }} [thumbLabel]
 */

/**
 * @param {SwitchProps & import("@chakra-ui/react").SwitchRootProps} props
 * @param {React.Ref<HTMLInputElement>} ref
 */
export const Switch = React.forwardRef(
    function Switch(props, ref) {
        const { inputProps, children, rootRef, trackLabel, thumbLabel, ...rest } =
            props

        return (
            <ChakraSwitch.Root ref={rootRef} {...rest}>
                <ChakraSwitch.HiddenInput ref={ref} {...inputProps} />
                <ChakraSwitch.Control>
                    <ChakraSwitch.Thumb>
                        {thumbLabel && (
                            <ChakraSwitch.ThumbIndicator fallback={thumbLabel?.off}>
                                {thumbLabel?.on}
                            </ChakraSwitch.ThumbIndicator>
                        )}
                    </ChakraSwitch.Thumb>
                    {trackLabel && (
                        <ChakraSwitch.Indicator fallback={trackLabel.off}>
                            {trackLabel.on}
                        </ChakraSwitch.Indicator>
                    )}
                </ChakraSwitch.Control>
                {children != null && (
                    <ChakraSwitch.Label>{children}</ChakraSwitch.Label>
                )}
            </ChakraSwitch.Root>
        )
    },
)
