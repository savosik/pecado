'use client'

import {
  Toaster as ChakraToaster,
  Portal,
  Spinner,
  Stack,
  Toast,
  createToaster,
} from '@chakra-ui/react'

export const toaster = createToaster({
  placement: 'bottom-end',
  pauseOnPageIdle: true,
})

export const Toaster = () => {
  return (
    <Portal>
      <ChakraToaster toaster={toaster} insetInline={{ mdDown: '4' }}>
        {(toast) => (
          <Toast.Root width={{ md: 'sm' }}>
            {toast.type === 'loading' ? (
              <Spinner size='sm' color='blue.solid' />
            ) : (
              <Toast.Indicator />
            )}
            <Stack gap='1' flex='1' maxWidth='100%'>
              {toast.title && <Toast.Title>{toast.title}</Toast.Title>}
              {toast.description && (
                <Toast.Description>{toast.description}</Toast.Description>
              )}
              {/* Кнопки действий (напоминания задач CRM): meta.buttons = [{label, onClick}] */}
              {toast.meta?.buttons?.length > 0 && (
                <Stack direction='row' gap='2' mt='1' flexWrap='wrap'>
                  {toast.meta.buttons.map((button) => (
                    <button
                      key={button.label}
                      type='button'
                      onClick={() => {
                        button.onClick?.()
                        toaster.dismiss(toast.id)
                      }}
                      style={{
                        fontSize: '12px',
                        fontWeight: 600,
                        padding: '2px 8px',
                        borderRadius: '4px',
                        border: '1px solid currentColor',
                        background: 'transparent',
                        color: 'inherit',
                        cursor: 'pointer',
                      }}
                    >
                      {button.label}
                    </button>
                  ))}
                </Stack>
              )}
            </Stack>
            {toast.action && (
              <Toast.ActionTrigger>{toast.action.label}</Toast.ActionTrigger>
            )}
            {toast.closable && <Toast.CloseTrigger />}
          </Toast.Root>
        )}
      </ChakraToaster>
    </Portal>
  )
}
