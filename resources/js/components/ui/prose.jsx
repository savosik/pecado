"use client"

import { chakra } from "@chakra-ui/react"

export const Prose = chakra("div", {
    base: {
        color: "fg.muted",
        maxWidth: "65ch",
        fontSize: "sm",
        lineHeight: "1.7em",
        "& :where(p):not(.not-prose, .not-prose *)": {
            marginTop: "1em",
            marginBottom: "1em",
        },
        "& :where(blockquote):not(.not-prose, .not-prose *)": {
            marginTop: "1.285em",
            marginBottom: "1.285em",
            paddingInline: "1.285em",
            borderInlineStartWidth: "0.25em",
            color: "fg",
        },
        "& :where(a):not(.not-prose, .not-prose *)": {
            color: "pecado.500",
            textDecoration: "underline",
            textUnderlineOffset: "3px",
            textDecorationThickness: "2px",
            fontWeight: "500",
        },
        "& :where(strong):not(.not-prose, .not-prose *)": {
            fontWeight: "600",
        },
        "& :where(em):not(.not-prose, .not-prose *)": {
            fontStyle: "italic",
        },
        "& :where(h1):not(.not-prose, .not-prose *)": {
            fontSize: "2.15em",
            letterSpacing: "-0.02em",
            marginTop: "0",
            marginBottom: "0.8em",
            lineHeight: "1.2em",
        },
        "& :where(h2):not(.not-prose, .not-prose *)": {
            fontSize: "1.65em",
            letterSpacing: "-0.02em",
            marginTop: "1.6em",
            marginBottom: "0.8em",
            lineHeight: "1.3em",
        },
        "& :where(h3):not(.not-prose, .not-prose *)": {
            fontSize: "1.35em",
            letterSpacing: "-0.01em",
            marginTop: "1.5em",
            marginBottom: "0.4em",
            lineHeight: "1.4em",
        },
        "& :where(h4):not(.not-prose, .not-prose *)": {
            marginTop: "1.4em",
            marginBottom: "0.5em",
            letterSpacing: "-0.01em",
            lineHeight: "1.5em",
        },
        "& :where(img):not(.not-prose, .not-prose *)": {
            marginTop: "1.7em",
            marginBottom: "1.7em",
            borderRadius: "lg",
            maxWidth: "100%",
        },
        "& :where(hr):not(.not-prose, .not-prose *)": {
            marginTop: "2.25em",
            marginBottom: "2.25em",
        },
        "& :where(ol):not(.not-prose, .not-prose *)": {
            marginTop: "1em",
            marginBottom: "1em",
            paddingInlineStart: "1.5em",
        },
        "& :where(ul):not(.not-prose, .not-prose *)": {
            marginTop: "1em",
            marginBottom: "1em",
            paddingInlineStart: "1.5em",
        },
        "& :where(li):not(.not-prose, .not-prose *)": {
            marginTop: "0.285em",
            marginBottom: "0.285em",
        },
        "& :where(ol > li):not(.not-prose, .not-prose *)": {
            paddingInlineStart: "0.4em",
            listStyleType: "decimal",
            "&::marker": {
                color: "fg.muted",
            },
        },
        "& :where(ul > li):not(.not-prose, .not-prose *)": {
            paddingInlineStart: "0.4em",
            listStyleType: "disc",
            "&::marker": {
                color: "fg.muted",
            },
        },
        "& :where(table):not(.not-prose, .not-prose *)": {
            width: "100%",
            tableLayout: "auto",
            textAlign: "start",
            lineHeight: "1.5em",
            marginTop: "2em",
            marginBottom: "2em",
        },
        "& :where(thead):not(.not-prose, .not-prose *)": {
            borderBottomWidth: "1px",
            color: "fg",
        },
        "& :where(tbody tr):not(.not-prose, .not-prose *)": {
            borderBottomWidth: "1px",
            borderBottomColor: "border",
        },
        "& :where(thead th):not(.not-prose, .not-prose *)": {
            paddingInlineEnd: "1em",
            paddingBottom: "0.65em",
            paddingInlineStart: "1em",
            fontWeight: "medium",
            textAlign: "start",
        },
        "& :where(tbody td):not(.not-prose, .not-prose *)": {
            paddingTop: "0.65em",
            paddingInlineEnd: "1em",
            paddingBottom: "0.65em",
            paddingInlineStart: "1em",
        },
        "& :where(figure):not(.not-prose, .not-prose *)": {
            marginTop: "1.625em",
            marginBottom: "1.625em",
        },
        "& :where(figcaption):not(.not-prose, .not-prose *)": {
            fontSize: "0.85em",
            lineHeight: "1.25em",
            marginTop: "0.85em",
            color: "fg.muted",
        },
        "& :where(code):not(.not-prose, .not-prose *)": {
            fontSize: "0.925em",
            bg: "bg.muted",
            letterSpacing: "-0.01em",
            lineHeight: "1",
            borderRadius: "md",
            borderWidth: "1px",
            paddingInline: "0.25em",
        },
        "& :where(pre):not(.not-prose, .not-prose *)": {
            backgroundColor: "bg.muted",
            marginTop: "1.6em",
            marginBottom: "1.6em",
            borderRadius: "md",
            fontSize: "0.9em",
            paddingTop: "0.65em",
            paddingBottom: "0.65em",
            paddingInlineEnd: "1em",
            paddingInlineStart: "1em",
            overflowX: "auto",
            fontWeight: "400",
        },
        "& :where(pre code):not(.not-prose, .not-prose *)": {
            fontSize: "inherit",
            letterSpacing: "inherit",
            borderWidth: "inherit",
            padding: "0",
            bg: "transparent",
        },
        "& :where(h1, h2, h3, h4, h5, h6):not(.not-prose, .not-prose *)": {
            color: "fg",
            fontWeight: "600",
        },
        "& :where(:is(h1,h2,h3,h4,h5,hr) + *):not(.not-prose, .not-prose *)": {
            marginTop: "0",
        },
    },
    variants: {
        size: {
            md: {
                fontSize: "sm",
            },
            lg: {
                fontSize: "md",
            },
        },
    },
    defaultVariants: {
        size: "md",
    },
})
