# Python中单行注释用#表示，#之后同行字符全部认为被注释

""" 与之对应的是多行注释
    用三个双引号表示，这两段双引号当中的内容都会被视作是注释
"""

values = []
kv = {'hello': 'world'}

# 获得一个整数
values[0] = 3
# 获得一个浮点数
values[1] = 10.0

c = 1 + 1  # => 2
d = 8 - 1  # => 7
e = 10 * 2  # => 20
f = 35 / 5  # => 7.0

g = 5 // 3  # => 1
h = -5 // 3  # => -2
j = 5.5 // 3.0  # => 1.0 # works on floats too
k = -5.0 // 3.0  # => -2.0

# Modulo operation
values[10] = 7 % 3  # => 1

# Exponentiation (x**y, x to the yth power)
values[11] = 2 ** 3  # => 8

# Enforce precedence with parentheses
values[12] = 1 + 3 * 2  # => 7
values[13] = (1 + 3) * 2  # => 8

_ = True  # => True
_ = False  # => False

_ = not True  # => False
_ = not False  # => True

# Boolean Operators
# Note "and" and "or" are case-sensitive
_ = True and False  # => False
_ = False or True  # => True

_ = True + True  # => 2
_ = True * 8  # => 8
_ = False - 5  # => -5

_ = 0 == False  # => True
_ = 1 == True  # => True
_ = 2 == True  # => False
_ = -5 != False  # => True

_ = bool(0)  # => False
_ = bool(4)  # => True
_ = bool(-6)  # => True
_ = 0 and 2  # => 0
_ = -5 or 0  # => -5

# Equality is ==
_ = 1 == 1  # => True
_ = 2 == 1  # => False

# Inequality is !=
_ = 1 != 1  # => False
_ = 2 != 1  # => True

# More comparisons
_ = 1 < 10  # => True
_ = 1 > 10  # => False
_ = 2 <= 2  # => True
_ = 2 >= 2  # => True

# Seeing whether a value is in a range
_ = 1 < 2 and 2 < 3  # => True
_ = 2 < 3 and 3 < 2  # => False
# Chaining makes this look nicer
_ = 1 < 2 < 3  # => True
_ = 2 < 3 < 2  # => False

a = [1, 2, 3, 4]  # Point a at a new list, [1, 2, 3, 4]
b = a  # Point b at what a is pointing to
_ = b is a  # => True, a and b refer to the same object
_ = b == a  # => True, a's and b's objects are equal
_ = b = [1, 2, 3, 4]  # Point b at a new list, [1, 2, 3, 4]
_ = b is a  # => False, a and b do not refer to the same object
_ = b == a  # => True, a's and b's objects are equal

# Strings are created with " or '
_ = "This is a string."
_ = 'This is also a string.'

# Strings can be added too! But try not to do this.
_ = "Hello " + "world!"  # => "Hello world!"
# String literals (but not variables) can be concatenated without using '+'
_ = "Hello " "world!"  # => "Hello world!"

# A string can be treated like a list of characters
_ = "This is a string"[0]  # => 'T'

# You can find the length of a string
_ = len("This is a string")  # => 16

# You can also format using f-strings or formatted string literals (in Python 3.6+)
name = "Reiko"
_ = f"She said her name is {name}."  # => "She said her name is Reiko"
# You can basically put any Python statement inside the braces and it will be output in the string.
_ = f"{name} is {len(name)} characters long."  # => "Reiko is 5 characters long."

# None is an object
_ = None  # => None

# Don't use the equality "==" symbol to compare objects to None
# Use "is" instead. This checks for equality of object identity.
_ = "etc" is None  # => False
_ = None is None  # => True

# None, 0, and empty strings/lists/dicts/tuples all evaluate to False.
# All other values are True
_ = bool(None)  # => False
_ = bool(0)  # => False
_ = bool("")  # => False
_ = bool([])  # => False
_ = bool({})  # => False
_ = bool(())  # => False

# Python has a print function
print("I'm Python. Nice to meet you!")  # => I'm Python. Nice to meet you!

# By default the print function also prints out a newline at the end.
# Use the optional argument end to change the end string.
print("Hello, World", end="!")  # => Hello, World!

# Simple way to get input data from console
input_string_var = input("Enter some data: ")  # Returns the data as a string
# Note: In earlier versions of Python, input() method was named as raw_input()

# There are no declarations, only assignments.
# Convention is to use lower_case_with_underscores
some_var = 5

# Accessing a previously unassigned variable is an exception.
# See Control Flow to learn more about exception handling.

# if can be used as an expression
# Equivalent of C's '?:' ternary operator
_ = "yahoo!" if 3 > 2 else 2  # => "yahoo!"


def test():
    if 3 > 2:
        return 'yahoo'
    else:
        return 2


# Lists store sequences
li = []
# You can start with a prefilled list
other_li = [4, 5, 6]

# Add stuff to the end of a list with append
li.append(1)  # li is now [1]
li.append(2)  # li is now [1, 2]
li.append(4)  # li is now [1, 2, 4]
li.append(3)  # li is now [1, 2, 4, 3]
# Remove from the end with pop
li.pop()  # => 3 and li is now [1, 2, 4]
# Let's put it back
li.append(3)  # li is now [1, 2, 4, 3] again.

# Access a list like you would any array
_ = li[0]  # => 1
# Look at the last element
_ = li[-1]  # => 3

# Looking out of bounds is an IndexError
_ = li[4]  # Raises an IndexError

# You can look at ranges with slice syntax.
# The start index is included, the end index is not
# (It's a closed/open range for you mathy types.)
_ = li[1:3]  # Return list from index 1 to 3 => [2, 4]
_ = li[2:]  # Return list starting from index 2 => [4, 3]
_ = li[:3]  # Return list from beginning until index 3  => [1, 2, 4]
_ = li[::2]  # Return list selecting every second entry => [1, 4]
_ = li[::-1]  # Return list in reverse order => [3, 4, 2, 1]
# Use any combination of these to make advanced slices
# li[start:end:step]

# Make a one layer deep copy using slices
li2 = li[:]  # => li2 = [1, 2, 4, 3] but (li2 is li) will result in false.

# Remove arbitrary elements from a list with "del"
del li[2]  # li is now [1, 2, 3]

# Remove first occurrence of a value
li.remove(2)  # li is now [1, 3]
li.remove(2)  # Raises a ValueError as 2 is not in the list

# Insert an element at a specific index
li.insert(1, 2)  # li is now [1, 2, 3] again

# Get the index of the first item found matching the argument
li.index(2)  # => 1
li.index(4)  # Raises a ValueError as 4 is not in the list

# Tuples are like lists but are immutable.
tup = (1, 2, 3)
tup[0]  # => 1
tup[0] = 3  # Raises a TypeError

type((1))  # => <class 'int'>
type((1,))  # => <class 'tuple'>
type(())  # => <class 'tuple'>

_ = len(tup)  # => 3
_ = tup + (4, 5, 6)  # => (1, 2, 3, 4, 5, 6)
_ = tup[:2]  # => (1, 2)
_ = 2 in tup  # => True

# You can unpack tuples (or lists) into variables
a, b, c = (1, 2, 3)  # a is now 1, b is now 2 and c is now 3
# You can also do extended unpacking
# Tuples are created by default if you leave out the parentheses
d, e, f = 4, 5, 6  # tuple 4, 5, 6 is unpacked into variables d, e and f
# respectively such that d = 4, e = 5 and f = 6
# Now look how easy it is to swap two values
e, d = d, e  # d is now 5 and e is now 4

# Look up values with []
invalid_dict = {1: "123"}
_ = invalid_dict["one"]  # => 1
_ = invalid_dict.get('one')  # => 1

# Here is a prefilled dictionary
filled_dict = {"one": 1, "two": 2, "three": 3}

# Get all keys as an iterable with "keys()". We need to wrap the call in list()
# to turn it into a list. We'll talk about those later.  Note - for Python
# versions <3.7, dictionary key ordering is not guaranteed. Your results might
# not match the example below exactly. However, as of Python 3.7, dictionary
# items maintain the order at which they are inserted into the dictionary.
_ = list(filled_dict.keys())  # => ["three", "two", "one"] in Python <3.7
_ = list(filled_dict.keys())  # => ["one", "two", "three"] in Python 3.7+

# Get all values as an iterable with "values()". Once again we need to wrap it
# in list() to get it out of the iterable. Note - Same as above regarding key
# ordering.
_ = list(filled_dict.values())  # => [3, 2, 1]  in Python <3.7
_ = list(filled_dict.values())  # => [1, 2, 3] in Python 3.7+

# Check for existence of keys in a dictionary with "in"
_ = "one" in filled_dict  # => True
_ = 1 in filled_dict  # => False

# _ = {'a': 1, **{'b': 2}}  # => {'a': 1, 'b': 2}
# _ = {'a': 1, **{'a': 2}}  # => {'a': 2}

# Sets store ... well sets
empty_set = set()
# Initialize a set with a bunch of values. Yeah, it looks a bit like a dict. Sorry.
some_set = {1, 1, 2, 2, 3, 4}  # some_set is now {1, 2, 3, 4}

# Do set intersection with &
# 计算交集
other_set = {3, 4, 5, 6}
filled_set = {1, 2, 3}
_ = filled_set & other_set  # => {3, 4, 5}

# Do set union with |
# 计算并集
_ = filled_set | other_set  # => {1, 2, 3, 4, 5, 6}

# Do set difference with -
# 计算差集
_ = {1, 2, 3, 4} - {2, 3, 5}  # => {1, 4}

# Do set symmetric difference with ^
# 这个有点特殊，计算对称集，也就是去掉重复元素剩下的内容
_ = {1, 2, 3, 4} ^ {2, 3, 5}  # => {1, 4, 5}

# Check if set on the left is a superset of set on the right
_ = {1, 2} >= {1, 2, 3}  # => False

# Check if set on the left is a subset of set on the right
_ = {1, 2} <= {1, 2, 3}  # => True

if some_var > 10:
    print("some_var is totally bigger than 10.")
elif some_var < 10:  # This elif clause is optional.
    print("some_var is smaller than 10.")
else:  # This is optional too.
    print("some_var is indeed 10.")

for animal in ["dog", "cat", "mouse"]:
    # You can use format() to interpolate formatted strings
    print("{} is a mammal".format(animal))

for i in range(4):
    print(i)

animals = ["dog", "cat", "mouse"]
for i, value in enumerate(animals):
    print(i, value)

x = 0
while x < 4:
    print(x)
    x += 1  # Shorthand for x = x + 1

# Handle exceptions with a try/except block
try:
    # Use "raise" to raise an error
    raise IndexError("This is an index error")
except IndexError as e:
    pass  # Pass is just a no-op. Usually you would do recovery here.
except (TypeError, NameError):
    pass  # Multiple exceptions can be handled together, if required.
finally:  # Execute under all circumstances
    print("We can clean up resources here")


# Instead of try/finally to cleanup resources you can use a with statement
# 代替使用try/finally语句来关闭资源
with open("myfile.txt") as f:
    for line in f:
        print(line)

# Writing to a file
# 使用with写入文件
contents = {"aa": 12, "bb": 21}
with open("myfile1.txt", "w+") as file:
    file.write(str(contents))        # writes a string to a file

with open("myfile2.txt", "w+") as file:
    file.write(json.dumps(contents)) # writes an object to a file

# Reading from a file
# 使用with读取文件
with open('myfile1.txt', "r+") as file:
    contents = file.read()           # reads a string from a file
print(contents)
# print: {"aa": 12, "bb": 21}

with open('myfile2.txt', "r+") as file:
    contents = json.load(file)       # reads a json object from a file
print(contents)
# print: {"aa": 12, "bb": 21}

# Python offers a fundamental abstraction called the Iterable.
# An iterable is an object that can be treated as a sequence.
# The object returned by the range function, is an iterable.

filled_dict = {"one": 1, "two": 2, "three": 3}
our_iterable = filled_dict.keys()
print(our_iterable)  # => dict_keys(['one', 'two', 'three']). This is an object that implements our Iterable interface.

# We can loop over it.
for i in our_iterable:
    print(i)  # Prints one, two, three

# However we cannot address elements by index.
our_iterable[1]  # Raises a TypeError

# An iterable is an object that knows how to create an iterator.
our_iterator = iter(our_iterable)

# Our iterator is an object that can remember the state as we traverse through it.
# We get the next object with "next()".
next(our_iterator)  # => "one"

# It maintains state as we iterate.
next(our_iterator)  # => "two"
next(our_iterator)  # => "three"

# After the iterator has returned all of its data, it raises a StopIteration exception
next(our_iterator)  # Raises StopIteration

# We can also loop over it, in fact, "for" does this implicitly!
our_iterator = iter(our_iterable)
for i in our_iterator:
    print(i)  # Prints one, two, three

# You can grab all the elements of an iterable or iterator by calling list() on it.
list(our_iterable)  # => Returns ["one", "two", "three"]
list(our_iterator)  # => Returns [] because state is saved

# Use "def" to create new functions
def add(x, y):
    print("x is {} and y is {}".format(x, y))
    return x + y  # Return values with a return statement

# Calling functions with parameters
add(5, 6)  # => prints out "x is 5 and y is 6" and returns 11

# Another way to call functions is with keyword arguments
add(y=6, x=5)  # Keyword arguments can arrive in any order.

# You can define functions that take a variable number of
# positional arguments
def varargs(*args):
    return args

varargs(1, 2, 3)  # => (1, 2, 3)

