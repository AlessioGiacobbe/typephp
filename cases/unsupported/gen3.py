
def none(lst, fn = lambda x: x):
    return all(not fn(x) for x in lst)

none([0, 1, 2, 0], lambda x: x >= 2 ) # False
none([0, 0, 0]) # True
