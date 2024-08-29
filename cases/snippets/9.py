def capitalize(s, lower_rest=False, val3=3423):
    return ''.join([s[:1].upper(), (s[1:].lower() if lower_rest else s[1:])])


capitalize('fooBar')  # 'FooBar'
capitalize('fooBar', True)  # 'Foobar'
