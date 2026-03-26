"use client"

import { Select as ChakraSelect, Portal } from "@chakra-ui/react"
import * as React from "react"

export const SelectTrigger = React.forwardRef(function SelectTrigger(props, ref) {
    const { children, ...rest } = props
    return (
        <ChakraSelect.Control {...rest}>
            <ChakraSelect.Trigger ref={ref}>{children}</ChakraSelect.Trigger>
            <ChakraSelect.IndicatorGroup>
                <ChakraSelect.Indicator />
            </ChakraSelect.IndicatorGroup>
        </ChakraSelect.Control>
    )
})

export const SelectContent = React.forwardRef(function SelectContent(props, ref) {
    const { portalled = true, portalRef, ...rest } = props
    return (
        <Portal disabled={!portalled} container={portalRef}>
            <ChakraSelect.Positioner>
                <ChakraSelect.Content {...rest} ref={ref} />
            </ChakraSelect.Positioner>
        </Portal>
    )
})

export const SelectItem = React.forwardRef(function SelectItem(props, ref) {
    const { item, children, ...rest } = props
    return (
        <ChakraSelect.Item item={item} {...rest} ref={ref}>
            {children}
            <ChakraSelect.ItemIndicator />
        </ChakraSelect.Item>
    )
})

export const SelectValueText = React.forwardRef(function SelectValueText(props, ref) {
    const { children, ...rest } = props
    return (
        <ChakraSelect.ValueText {...rest} ref={ref}>
            {children}
        </ChakraSelect.ValueText>
    )
})

export const SelectRoot = React.forwardRef(function SelectRoot(props, ref) {
    return <ChakraSelect.Root {...props} ref={ref} />
})

export const SelectItemGroup = React.forwardRef(function SelectItemGroup(props, ref) {
    const { children, label, ...rest } = props
    return (
        <ChakraSelect.ItemGroup {...rest} ref={ref}>
            <ChakraSelect.ItemGroupLabel>{label}</ChakraSelect.ItemGroupLabel>
            {children}
        </ChakraSelect.ItemGroup>
    )
})

export const SelectLabel = ChakraSelect.Label
export const SelectItemText = ChakraSelect.ItemText

// Compatibility export
export const Select = {
    Root: SelectRoot,
    Trigger: SelectTrigger,
    Content: SelectContent,
    Item: SelectItem,
    ValueText: SelectValueText,
    Label: SelectLabel,
    ItemGroup: SelectItemGroup,
    ItemText: SelectItemText
}
