
def find_last_index(lst, fn):
    return len(lst) - 1 - next(i for i, x in enumerate(lst[::-1]) if fn(x))

find_last_index([1, 2, 3, 4], lambda n: n % 2 == 1) # 2
