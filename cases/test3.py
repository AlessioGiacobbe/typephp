
def invert_dictionary(obj):
    return { value: key for key, value in obj.items() }

ages = {
    'Peter': 10,
    'Isabel': 11,
    'Anna': 9,
}
invert_dictionary(ages) # { 10: 'Peter', 11: 'Isabel', 9: 'Anna' }
