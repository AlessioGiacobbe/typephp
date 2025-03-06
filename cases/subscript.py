import sys

a = [6, 7, 8, 9, 10]

a[1] = 3
b = a[2]
del a[3]

print(a[1:4])
del a[1:4]

m = {'a': 1, 'b': 2, 'c': 3}
print(m['b'])
del m['b']
c = m['c']
