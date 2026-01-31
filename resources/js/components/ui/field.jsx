import { Field as ChakraField, Text } from "@chakra-ui/react"
import * as React from "react"

export const Field = React.forwardRef(function Field(props, ref) {
    const { label, children, helperText, errorText, optionalText, ...rest } = props
    return (
        <ChakraField.Root ref={ref} {...rest}>
            {label && (
                <ChakraField.Label>
                    {label}
                    {optionalText && (
                        <Text as="span" fontSize="sm" color="fg.muted" fontWeight="normal" ml={1}>
                            ({optionalText})
                        </Text>
                    )}
                </ChakraField.Label>
            )}
            {children}
            {helperText && <ChakraField.HelperText>{helperText}</ChakraField.HelperText>}
            {errorText && <ChakraField.ErrorText>{errorText}</ChakraField.ErrorText>}
        </ChakraField.Root>
    )
})
